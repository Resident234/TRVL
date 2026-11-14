<?php

declare(strict_types=1);

namespace app\shared\Forum\Infrastructure;

use app\shared\Forum\Contract\TagRepositoryInterface;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage for tags: all SQL lives here, the service above only
 * sees ids, titles and canonical tag names.
 */
final class TagRepository implements TagRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Session-level advisory lock: held until the process finishes or the DB
     * session drops, so a crashed pass never blocks the next launch.
     */
    public function acquireLock(string $code): bool
    {
        return (bool)$this->db
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
        return (int)sprintf('%u', crc32($code));
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    public function forums(?int $from = null, ?int $to = null, ?int $limit = null): array
    {
        $sql = 'SELECT id, title FROM {{%forum}}';
        $conditions = [];
        $binds = [];
        if ($from !== null) {
            $conditions[] = 'id >= :from';
            $binds[':from'] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'id <= :to';
            $binds[':to'] = $to;
        }
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY id';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
            $binds[':limit'] = $limit;
        }

        $rows = $this->db
            ->createCommand($sql)
            ->bindValues($binds)
            ->queryAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
            ],
            $rows,
        );
    }

    public function saveForumTags(int $forumId, array $tagNames, string $now): array
    {
        $tagsCreated = 0;
        $linksCreated = 0;
        $tagIds = [];

        $transaction = $this->db->beginTransaction();
        try {
            foreach ($tagNames as $name) {
                $name = trim((string)$name);
                if ($name === '' || isset($tagIds[$name])) {
                    continue;
                }

                $tagId = $this->db
                    ->createCommand('SELECT id FROM {{%tag}} WHERE name = :name')
                    ->bindValue(':name', $name)
                    ->queryScalar();
                if ($tagId === false) {
                    $tagId = (int)$this->db
                        ->createCommand(
                            'INSERT INTO {{%tag}} (name, created_at, updated_at)'
                            . ' VALUES (:name, :now, :now) RETURNING id',
                        )
                        ->bindValues([':name' => $name, ':now' => $now])
                        ->queryScalar();
                    $tagsCreated++;
                }

                $tagIds[$name] = (int)$tagId;
            }

            $ids = array_values($tagIds);
            if ($ids === []) {
                $this->db
                    ->createCommand('DELETE FROM {{%forum_tag}} WHERE forum_id = :forum_id')
                    ->bindValue(':forum_id', $forumId)
                    ->execute();
            } else {
                $this->db
                    ->createCommand(
                        'DELETE FROM {{%forum_tag}} WHERE forum_id = :forum_id'
                        . ' AND tag_id NOT IN (' . implode(',', $ids) . ')',
                    )
                    ->bindValue(':forum_id', $forumId)
                    ->execute();
            }

            foreach ($ids as $tagId) {
                $linksCreated += (int)$this->db
                    ->createCommand(
                        'INSERT INTO {{%forum_tag}} (forum_id, tag_id, created_at)'
                        . ' VALUES (:forum_id, :tag_id, :now)'
                        . ' ON CONFLICT (forum_id, tag_id) DO NOTHING',
                    )
                    ->bindValues([':forum_id' => $forumId, ':tag_id' => $tagId, ':now' => $now])
                    ->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return ['tags_created' => $tagsCreated, 'links_created' => $linksCreated];
    }
}
