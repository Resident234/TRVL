<?php

declare(strict_types=1);

namespace app\shared\Publications\Infrastructure;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\MessageEntities;
use InvalidArgumentException;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage for channel publications. All SQL lives here:
 * upper layers receive and return DTOs only.
 */
final class PublicationRepository implements PublicationRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function allPosts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->page(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at'
            . ' FROM {{%publications_post}}'
            . $this->order('published_at', $oldestFirst),
            $limit,
            $offset,
        );
    }

    public function allDrafts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->page(
            'SELECT id, text, image_urls, formatting, button, NULL AS telegram_id, NULL AS published_at, created_at, updated_at'
            . ' FROM {{%publications_draft}}'
            . $this->order('updated_at', $oldestFirst),
            $limit,
            $offset,
        );
    }

    public function countPosts(): int
    {
        return $this->countOf('{{%publications_post}}');
    }

    public function countDrafts(): int
    {
        return $this->countOf('{{%publications_draft}}');
    }

    public function createDraft(
        string $text,
        array $imageUrls,
        string $now,
        MessageEntities $formatting,
        LinkButton $button,
    ): int {
        return $this->insertReturningId('{{%publications_draft}}', [
            'text' => $text,
            'image_urls' => $imageUrls,
            'formatting' => $formatting->toArray(),
            'button' => $button->toArray(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function createPost(
        string $text,
        array $imageUrls,
        string $publishedAt,
        string $now,
        MessageEntities $formatting,
        LinkButton $button,
    ): int {
        return $this->insertReturningId('{{%publications_post}}', [
            'text' => $text,
            'image_urls' => $imageUrls,
            'formatting' => $formatting->toArray(),
            'button' => $button->toArray(),
            'published_at' => $publishedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, button: LinkButton, publishedAt: string}> $parts
     */
    public function createPosts(array $parts, string $now): int
    {
        return $this->insertParts('{{%publications_post}}', $parts, $now, true);
    }

    /**
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, button: LinkButton}> $parts
     */
    public function createDrafts(array $parts, string $now): int
    {
        return $this->insertParts('{{%publications_draft}}', $parts, $now, false);
    }

    public function findDueForPublishing(string $now): array
    {
        return $this->hydrateAll(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at'
            . ' FROM {{%publications_post}}'
            . ' WHERE telegram_id IS NULL AND published_at <= :now'
            . ' ORDER BY id ASC',
            [':now' => $now],
        );
    }

    public function storeTelegramId(int $id, int $telegramId, string $publishedAt, string $now): void
    {
        $this->db
            ->createCommand()
            ->update('{{%publications_post}}', [
                'telegram_id' => $telegramId,
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ], ['id' => $id])
            ->execute();
    }

    public function findPost(int $id): ?PublicationData
    {
        return $this->hydrateOne(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at'
            . ' FROM {{%publications_post}} WHERE id = :id',
            [':id' => $id],
        );
    }

    public function findDraft(int $id): ?PublicationData
    {
        return $this->hydrateOne(
            'SELECT id, text, image_urls, formatting, button, NULL AS telegram_id, NULL AS published_at, created_at, updated_at'
            . ' FROM {{%publications_draft}} WHERE id = :id',
            [':id' => $id],
        );
    }

    public function updatePost(
        int $id,
        string $text,
        array $imageUrls,
        string $publishedAt,
        string $now,
        MessageEntities $formatting,
        LinkButton $button,
    ): void {
        $this->db
            ->createCommand()
            ->update('{{%publications_post}}', [
                'text' => $text,
                'image_urls' => $imageUrls,
                'formatting' => $formatting->toArray(),
                'button' => $button->toArray(),
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ], ['id' => $id])
            ->execute();
    }

    public function updateDraft(
        int $id,
        string $text,
        array $imageUrls,
        string $now,
        MessageEntities $formatting,
        LinkButton $button,
    ): void {
        $this->db
            ->createCommand()
            ->update('{{%publications_draft}}', [
                'text' => $text,
                'image_urls' => $imageUrls,
                'formatting' => $formatting->toArray(),
                'button' => $button->toArray(),
                'updated_at' => $now,
            ], ['id' => $id])
            ->execute();
    }

    public function deletePost(int $id): PublicationData
    {
        $post = $this->findPost($id);
        if ($post === null) {
            throw new InvalidArgumentException("Публикация #{$id} не найдена.");
        }

        $this->db
            ->createCommand()
            ->delete('{{%publications_post}}', ['id' => $id])
            ->execute();

        return $post;
    }

    public function deleteDraft(int $id): PublicationData
    {
        $draft = $this->findDraft($id);
        if ($draft === null) {
            throw new InvalidArgumentException("Черновик #{$id} не найден.");
        }

        $this->db
            ->createCommand()
            ->delete('{{%publications_draft}}', ['id' => $id])
            ->execute();

        return $draft;
    }

    public function insertPostWithHistory(PublicationData $post, string $now): int
    {
        return $this->insertReturningId('{{%publications_post}}', [
            'telegram_id' => $post->telegramId,
            'text' => $post->text,
            'image_urls' => $post->imageUrls,
            'formatting' => $post->formatting->toArray(),
            'button' => $post->button->toArray(),
            'published_at' => $post->publishedAt ?? $now,
            'created_at' => $post->createdAt,
            'updated_at' => $now,
        ]);
    }

    public function insertDraftWithHistory(PublicationData $draft, string $now): int
    {
        return $this->insertReturningId('{{%publications_draft}}', [
            'text' => $draft->text,
            'image_urls' => $draft->imageUrls,
            'formatting' => $draft->formatting->toArray(),
            'button' => $draft->button->toArray(),
            'created_at' => $draft->createdAt,
            'updated_at' => $now,
        ]);
    }

    public function archiveEdited(PublicationData $post, string $now): void
    {
        $this->db
            ->createCommand()
            ->insert('{{%publications_edited}}', [
                'telegram_id' => $post->telegramId,
                'text' => $post->text,
                'image_urls' => $post->imageUrls,
                'formatting' => $post->formatting->toArray(),
                'button' => $post->button->toArray(),
                'published_at' => $post->publishedAt ?? $now,
                'created_at' => $post->createdAt,
                'updated_at' => $now,
                'edited_at' => null,
            ])
            ->execute();
    }

    public function allDeleted(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->page(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at, deleted_at'
            . ' FROM {{%publications_deleted}}'
            . $this->order('updated_at', $oldestFirst),
            $limit,
            $offset,
        );
    }

    public function countDeleted(): int
    {
        return $this->countOf('{{%publications_deleted}}');
    }

    public function findDeleted(int $id): ?PublicationData
    {
        return $this->hydrateOne(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at, deleted_at'
            . ' FROM {{%publications_deleted}} WHERE id = :id',
            [':id' => $id],
        );
    }

    public function deleteDeleted(int $id): PublicationData
    {
        $record = $this->findDeleted($id);
        if ($record === null) {
            throw new InvalidArgumentException("Удалённая запись #{$id} не найдена.");
        }

        $this->db
            ->createCommand()
            ->delete('{{%publications_deleted}}', ['id' => $id])
            ->execute();

        return $record;
    }

    public function findPendingChannelDeletion(): array
    {
        return $this->hydrateAll(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at'
            . ' FROM {{%publications_deleted}}'
            . ' WHERE deleted_at IS NULL'
            . ' ORDER BY id ASC',
        );
    }

    public function insertDeletedWithHistory(PublicationData $record, string $now): int
    {
        return $this->insertReturningId('{{%publications_deleted}}', [
            'telegram_id' => $record->telegramId,
            'text' => $record->text,
            'image_urls' => $record->imageUrls,
            'formatting' => $record->formatting->toArray(),
            'button' => $record->button->toArray(),
            'published_at' => $record->publishedAt,
            'created_at' => $record->createdAt,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
    }

    public function storeDeletedAt(int $id, string $deletedAt): void
    {
        $this->db
            ->createCommand()
            ->update('{{%publications_deleted}}', ['deleted_at' => $deletedAt], ['id' => $id])
            ->execute();
    }

    public function findPendingChannelEdits(): array
    {
        return $this->hydrateAll(
            'SELECT id, text, image_urls, formatting, button, telegram_id, published_at, created_at, updated_at'
            . ' FROM {{%publications_edited}}'
            . ' WHERE edited_at IS NULL'
            . ' ORDER BY id ASC',
        );
    }

    public function storeEditedAt(int $id, string $editedAt): void
    {
        $this->db
            ->createCommand()
            ->update('{{%publications_edited}}', ['edited_at' => $editedAt], ['id' => $id])
            ->execute();
    }

    /**
     * Stores the parts of one publication as separate rows inside a
     * transaction, so a failure halfway through the list gives back an
     * empty table rather than a publication cut in pieces.
     *
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, button: LinkButton, publishedAt?: string}> $parts
     */
    private function insertParts(string $table, array $parts, string $now, bool $scheduled): int
    {
        $ids = [];
        $transaction = $this->db->beginTransaction();
        try {
            foreach ($parts as $part) {
                $columns = [
                    'text' => $part['text'],
                    'image_urls' => $part['imageUrls'],
                    'formatting' => $part['formatting']->toArray(),
                    'button' => $part['button']->toArray(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($scheduled) {
                    $columns['published_at'] = $part['publishedAt'];
                }

                $ids[] = $this->insertReturningId($table, $columns);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        return $ids[0];
    }

    /**
     * Inserts a row and returns the id the database gave it.
     *
     * @param array<string, mixed> $columns
     */
    private function insertReturningId(string $table, array $columns): int
    {
        $this->db
            ->createCommand()
            ->insert($table, $columns)
            ->execute();

        return (int)$this->db->getLastInsertID($this->sequenceName($table));
    }

    /**
     * id serial columns produce a "<table>_id_seq" sequence name.
     */
    private function sequenceName(string $table): string
    {
        return trim($table, '{}%') . '_id_seq';
    }

    /**
     * @param array<string, int|string> $params
     */
    private function hydrateOne(string $sql, array $params = []): ?PublicationData
    {
        $row = $this->db
            ->createCommand($sql)
            ->bindValues($params)
            ->queryOne(PDO::FETCH_ASSOC);

        if ($row === false || $row === []) {
            return null;
        }

        return new PublicationData(
            (int)$row['id'],
            (string)$row['text'],
            self::decodeImageUrls($row['image_urls']),
            $row['telegram_id'] === null ? null : (int)$row['telegram_id'],
            $row['published_at'] === null ? null : (string)$row['published_at'],
            (string)$row['created_at'],
            (string)$row['updated_at'],
            array_key_exists('deleted_at', $row)
                ? ($row['deleted_at'] === null ? null : (string)$row['deleted_at'])
                : null,
            MessageEntities::fromStored($row['formatting']),
            LinkButton::fromStored($row['button']),
        );
    }

    /**
     * @param array<string, int|string> $params
     * @return PublicationData[]
     */
    private function hydrateAll(string $sql, array $params = []): array
    {
        $rows = $this->db
            ->createCommand($sql)
            ->bindValues($params)
            ->queryAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): PublicationData => new PublicationData(
                (int)$row['id'],
                (string)$row['text'],
                self::decodeImageUrls($row['image_urls']),
                $row['telegram_id'] === null ? null : (int)$row['telegram_id'],
                $row['published_at'] === null ? null : (string)$row['published_at'],
                (string)$row['created_at'],
                (string)$row['updated_at'],
                array_key_exists('deleted_at', $row)
                    ? ($row['deleted_at'] === null ? null : (string)$row['deleted_at'])
                    : null,
                MessageEntities::fromStored($row['formatting']),
                LinkButton::fromStored($row['button']),
            ),
            $rows,
        );
    }

    /**
     * The order of a list: newest first, oldest first when the block asks for
     * the reverse. Both orders end in the row id, so pages of the same order
     * neither overlap nor skip a row.
     */
    private function order(string $column, bool $oldestFirst): string
    {
        $direction = $oldestFirst ? 'ASC' : 'DESC';

        return ' ORDER BY ' . $column . ' ' . $direction . ', id ' . $direction;
    }

    /**
     * One page of an ordered list. Every list read through here ends its
     * ORDER BY in the row id, so pages neither overlap nor skip a row while
     * the table holds still; a zero limit reads the whole table, which is
     * what the periodic tasks do.
     *
     * @return PublicationData[]
     */
    private function page(string $sql, int $limit, int $offset): array
    {
        if ($limit <= 0) {
            return $this->hydrateAll($sql);
        }

        return $this->hydrateAll(
            $sql . ' LIMIT :limit OFFSET :offset',
            [':limit' => $limit, ':offset' => max(0, $offset)],
        );
    }

    private function countOf(string $table): int
    {
        return (int)$this->db
            ->createCommand('SELECT COUNT(*) FROM ' . $table)
            ->queryScalar();
    }

    /**
     * jsonb columns arrive as JSON strings; the Yii pgsql schema
     * encodes array parameters on write, so arrays pass through.
     *
     * @return string[]
     */
    private static function decodeImageUrls(mixed $value): array
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $url): string => (string)$url, $value);
        }

        $decoded = json_decode((string)$value, true);

        return is_array($decoded)
            ? array_map(static fn (mixed $url): string => (string)$url, $decoded)
            : [];
    }
}
