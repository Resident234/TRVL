<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Infrastructure;

use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Infrastructure\LlmJobRepository;
use Codeception\Test\Unit;
use yii\db\Connection;
use Yii;

/**
 * The queue table without a worker: a row is put in, claimed once, finished, and
 * coaxed back into the queue when the process that claimed it died. The lock is
 * proved over a second connection, because a session-level advisory lock asked
 * for twice by the same session is granted twice — and that would test nothing.
 */
final class LlmJobRepositoryTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const GROUP = '0123456789abcdef0123456789abcdef';

    private const LOCK_CODE = 'llm_job';

    private const MARKS = '[{"type":"bold","offset":0,"length":8}]';

    private LlmJobRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%llm_job}}')
            ->execute();
        $this->_repository = new LlmJobRepository(Yii::$app->getDb());
    }

    private function job(int $order = 0): LlmJobData
    {
        return new LlmJobData(
            0,
            LlmJobData::QUEUED,
            LlmJobData::KIND_STYLE,
            self::GROUP,
            $order,
            self::STYLE,
            'Братишка, мы в Америке.',
            self::MARKS,
        );
    }

    public function testEnqueueReturnsAnIdAndReadsTheRowBack(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');

        $row = $this->_repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame($id, $row->id);
        $this->assertSame(LlmJobData::QUEUED, $row->status);
        $this->assertSame(LlmJobData::KIND_STYLE, $row->kind);
        $this->assertSame(self::GROUP, $row->partGroupId);
        $this->assertSame(0, $row->partOrder);
        $this->assertSame(self::STYLE, $row->styleName);
        $this->assertSame('Братишка, мы в Америке.', $row->sourceText);
        $this->assertSame(self::MARKS, $row->sourceFormatting);
        $this->assertNull($row->resultText);
        $this->assertNull($row->resultFormatting);
        $this->assertNull($row->error);
        $this->assertNull($row->startedAt);
        $this->assertNull($row->finishedAt);
        $this->assertSame('2026-11-05 10:00:00', $row->createdAt);
    }

    public function testFindOfAnUnknownIdIsNull(): void
    {
        $this->assertNull($this->_repository->find(424242));
    }

    public function testClaimTakesTheOldestQueuedRow(): void
    {
        $first = $this->_repository->enqueue($this->job(0), '2026-11-05 10:00:00');
        $this->_repository->enqueue($this->job(1), '2026-11-05 10:00:01');

        $claimed = $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $this->assertNotNull($claimed);
        $this->assertSame($first, $claimed->id);
        $this->assertSame(LlmJobData::RUNNING, $claimed->status);
        $this->assertSame('2026-11-05 10:02:00', $claimed->startedAt);
    }

    public function testClaimGivesARowOnlyOnce(): void
    {
        $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');

        $this->assertNotNull($this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00'));
        $this->assertNull($this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:01'));
    }

    public function testClaimOfAnEmptyQueueIsNull(): void
    {
        $this->assertNull($this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00'));
    }

    public function testMarkDoneKeepsTheAnswerAndItsTime(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $this->_repository->markDone($id, 'Ну здравствуй, братишка.', '[]', '2026-11-05 10:03:00');

        $row = $this->_repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::DONE, $row->status);
        $this->assertSame('Ну здравствуй, братишка.', $row->resultText);
        $this->assertSame('[]', $row->resultFormatting);
        $this->assertNull($row->error);
        $this->assertSame('2026-11-05 10:03:00', $row->finishedAt);
    }

    public function testMarkFailedKeepsTheReasonAndNoAnswer(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $this->_repository->markFailed($id, 'Прокси не принял ключ.', '2026-11-05 10:03:00');

        $row = $this->_repository->find($id);

        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::FAILED, $row->status);
        $this->assertNull($row->resultText);
        $this->assertNull($row->resultFormatting);
        $this->assertSame('Прокси не принял ключ.', $row->error);
        $this->assertSame('2026-11-05 10:03:00', $row->finishedAt);
    }

    public function testReclaimReturnsAnAbandonedRowToTheQueue(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $moved = $this->_repository->reclaimStale('2026-11-05 10:05:00');

        $row = $this->_repository->find($id);

        $this->assertSame(1, $moved);
        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::QUEUED, $row->status);
        $this->assertNull($row->startedAt);
    }

    public function testReclaimLeavesAFreshRunningRowAlone(): void
    {
        $id = $this->_repository->enqueue($this->job(), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');

        $moved = $this->_repository->reclaimStale('2026-11-05 10:01:00');

        $row = $this->_repository->find($id);

        $this->assertSame(0, $moved);
        $this->assertNotNull($row);
        $this->assertSame(LlmJobData::RUNNING, $row->status);
    }

    public function testPurgeDeletesOnlyFinishedRowsPastTheHorizon(): void
    {
        $old = $this->_repository->enqueue($this->job(0), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');
        $this->_repository->markDone($old, 'Готово.', '[]', '2026-11-05 10:03:00');
        $fresh = $this->_repository->enqueue($this->job(1), '2026-11-05 10:00:00');
        $this->_repository->claim(LlmJobData::KIND_STYLE, '2026-11-05 10:02:00');
        $this->_repository->markFailed($fresh, 'Упало.', '2026-11-05 11:00:00');
        $waiting = $this->_repository->enqueue($this->job(2), '2026-11-05 10:00:00');

        $deleted = $this->_repository->purgeFinished('2026-11-05 10:30:00');

        $this->assertSame(1, $deleted);
        $this->assertNull($this->_repository->find($old));
        $this->assertNotNull($this->_repository->find($fresh));
        $this->assertNotNull($this->_repository->find($waiting));
    }

    public function testTheLockIsHeldByOneSessionAndFreeAfterIt(): void
    {
        $other = new Connection([
            'dsn' => Yii::$app->db->dsn,
            'username' => Yii::$app->db->username,
            'password' => Yii::$app->db->password,
        ]);
        $other->open();
        $key = (int) sprintf('%u', crc32(self::LOCK_CODE));

        try {
            $this->assertTrue($this->_repository->acquireLock(self::LOCK_CODE));
            $this->assertSame(
                0,
                (int) $other
                    ->createCommand('SELECT CASE WHEN pg_try_advisory_lock(:key) THEN 1 ELSE 0 END')
                    ->bindValue(':key', $key)
                    ->queryScalar(),
            );

            $this->_repository->releaseLock(self::LOCK_CODE);

            $this->assertSame(
                1,
                (int) $other
                    ->createCommand('SELECT CASE WHEN pg_try_advisory_lock(:key) THEN 1 ELSE 0 END')
                    ->bindValue(':key', $key)
                    ->queryScalar(),
            );
            $other->createCommand('SELECT pg_advisory_unlock(:key)')->bindValue(':key', $key)->execute();
        } finally {
            $other->close();
        }
    }
}
