<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmJobData;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage for the queue of background LLM work. All SQL lives here:
 * the layers above hand DTOs over and never a column name.
 *
 * There is no claim pattern to copy in this project — no FOR UPDATE, no SKIP
 * LOCKED anywhere — so a row is taken by one UPDATE that also says it is taken,
 * and the step of the cron against itself is held off by the same session-level
 * advisory lock the forum scans use. A step is a minute and a stylization can
 * outlast it, so the lock is not a formality.
 */
final class LlmJobRepository implements LlmJobQueueInterface
{
    private const COLUMNS = 'id, status, kind, part_group_id, part_order, style_name,'
        . ' source_text, source_formatting, result_text, result_formatting, error,'
        . ' created_at, started_at, finished_at';

    public function __construct(private readonly Connection $db)
    {
    }

    public function enqueue(LlmJobData $job, string $now): int
    {
        $this->db
            ->createCommand()
            ->insert('{{%llm_job}}', [
                'status' => LlmJobData::QUEUED,
                'kind' => $job->kind,
                'part_group_id' => $job->partGroupId,
                'part_order' => $job->partOrder,
                'style_name' => $job->styleName,
                'source_text' => $job->sourceText,
                'source_formatting' => $this->decoded($job->sourceFormatting),
                'created_at' => $now,
            ])
            ->execute();

        return (int) $this->db->getLastInsertID('llm_job_id_seq');
    }

    public function find(int $id): ?LlmJobData
    {
        $row = $this->db
            ->createCommand('SELECT ' . self::COLUMNS . ' FROM {{%llm_job}} WHERE id = :id')
            ->bindValue(':id', $id)
            ->queryOne(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->rowToJob($row) : null;
    }

    public function claim(string $kind, string $now): ?LlmJobData
    {
        $id = (int) $this->db
            ->createCommand(
                'SELECT id FROM {{%llm_job}} WHERE status = :queued AND kind = :kind'
                . ' ORDER BY id ASC LIMIT 1',
            )
            ->bindValue(':queued', LlmJobData::QUEUED)
            ->bindValue(':kind', $kind)
            ->queryScalar();
        if ($id === 0) {
            return null;
        }

        $taken = $this->db
            ->createCommand(
                'UPDATE {{%llm_job}} SET status = :running, started_at = :now'
                . ' WHERE id = :id AND status = :queued',
            )
            ->bindValue(':running', LlmJobData::RUNNING)
            ->bindValue(':now', $now)
            ->bindValue(':id', $id)
            ->bindValue(':queued', LlmJobData::QUEUED)
            ->execute();
        if ($taken !== 1) {
            return null;
        }

        return $this->find($id);
    }

    public function markDone(int $id, string $resultText, string $resultFormatting, string $now): void
    {
        $this->db
            ->createCommand()
            ->update('{{%llm_job}}', [
                'status' => LlmJobData::DONE,
                'result_text' => $resultText,
                'result_formatting' => $this->decoded($resultFormatting),
                'finished_at' => $now,
            ], ['id' => $id, 'status' => LlmJobData::RUNNING])
            ->execute();
    }

    public function markFailed(int $id, string $error, string $now): void
    {
        $this->db
            ->createCommand()
            ->update('{{%llm_job}}', [
                'status' => LlmJobData::FAILED,
                'error' => $error,
                'finished_at' => $now,
            ], ['id' => $id, 'status' => LlmJobData::RUNNING])
            ->execute();
    }

    public function reclaimStale(string $startedBefore): int
    {
        return $this->db
            ->createCommand(
                'UPDATE {{%llm_job}} SET status = :queued, started_at = NULL'
                . ' WHERE status = :running AND started_at < :before',
            )
            ->bindValue(':queued', LlmJobData::QUEUED)
            ->bindValue(':running', LlmJobData::RUNNING)
            ->bindValue(':before', $startedBefore)
            ->execute();
    }

    public function purgeFinished(string $finishedBefore): int
    {
        return $this->db
            ->createCommand(
                'DELETE FROM {{%llm_job}} WHERE status IN (:done, :failed) AND finished_at < :before',
            )
            ->bindValue(':done', LlmJobData::DONE)
            ->bindValue(':failed', LlmJobData::FAILED)
            ->bindValue(':before', $finishedBefore)
            ->execute();
    }

    public function acquireLock(string $code): bool
    {
        return (bool) $this->db
            ->createCommand('SELECT pg_try_advisory_lock(:key)')
            ->bindValue(':key', $this->lockKey($code))
            ->queryScalar();
    }

    public function releaseLock(string $code): void
    {
        $this->db
            ->createCommand('SELECT pg_advisory_unlock(:key)')
            ->bindValue(':key', $this->lockKey($code))
            ->execute();
    }

    private function lockKey(string $code): int
    {
        return (int) sprintf('%u', crc32($code));
    }

    /**
     * The marks go into the jsonb column the way the four publications tables
     * already put theirs: Yii encodes an array parameter on write. A string that
     * is not a JSON list is stored as the empty list — the same reading the
     * column of a part gives for a part nobody marked.
     *
     * @return array<int, mixed>
     */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The marks as the column hands them back: a JSON string from the driver, an
     * already decoded list where the schema let the array through. The form wants
     * the string, and `MessageEntities::fromStored()` reads that.
     */
    private static function formattingOf(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToJob(array $row): LlmJobData
    {
        return new LlmJobData(
            (int) $row['id'],
            (string) $row['status'],
            (string) $row['kind'],
            (string) $row['part_group_id'],
            (int) $row['part_order'],
            (string) $row['style_name'],
            (string) $row['source_text'],
            self::formattingOf($row['source_formatting']),
            $row['result_text'] === null ? null : (string) $row['result_text'],
            $row['result_formatting'] === null ? null : self::formattingOf($row['result_formatting']),
            $row['error'] === null ? null : (string) $row['error'],
            (string) $row['created_at'],
            $row['started_at'] === null ? null : (string) $row['started_at'],
            $row['finished_at'] === null ? null : (string) $row['finished_at'],
        );
    }
}
