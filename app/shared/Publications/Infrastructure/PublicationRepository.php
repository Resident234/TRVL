<?php

declare(strict_types=1);

namespace app\shared\Publications\Infrastructure;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Telegram\Dto\LinkButtons;
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
    /**
     * The columns every read of a publication row carries: the text of the part,
     * the images it is sent with, the marks of the body and of the heading, the
     * buttons under the message and the heading itself.
     */
    private const CONTENT_COLUMNS = 'id, text, image_urls, formatting, button, title, title_formatting, ';

    public function __construct(private readonly Connection $db)
    {
    }

    public function allPosts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->page(
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at, '
            . $this->partColumns()
            . ' FROM {{%publications_post}}'
            . $this->order('published_at', $oldestFirst),
            $limit,
            $offset,
        );
    }

    public function allDrafts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->page(
            'SELECT ' . self::CONTENT_COLUMNS . 'NULL AS telegram_id, NULL AS published_at, created_at, updated_at, '
            . $this->awayPartColumns() . $this->partGroupTotal('publications_draft')
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
        LinkButtons $buttons,
        string $title,
        MessageEntities $titleFormatting,
        string $groupId = '',
        int $partOrder = 0,
    ): int {
        return $this->insertReturningId('{{%publications_draft}}', array_merge([
            'text' => $text,
            'image_urls' => $imageUrls,
            'formatting' => $formatting->toArray(),
            'button' => $buttons->toArray(),
            'title' => $title,
            'title_formatting' => $titleFormatting->toArray(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->groupColumns($groupId, $partOrder, true)));
    }

    public function createPost(
        string $text,
        array $imageUrls,
        string $publishedAt,
        string $now,
        MessageEntities $formatting,
        LinkButtons $buttons,
        string $title,
        MessageEntities $titleFormatting,
        string $groupId = '',
        int $partOrder = 0,
        bool $partReleased = true,
    ): int {
        return $this->insertReturningId('{{%publications_post}}', array_merge([
            'text' => $text,
            'image_urls' => $imageUrls,
            'formatting' => $formatting->toArray(),
            'button' => $buttons->toArray(),
            'title' => $title,
            'title_formatting' => $titleFormatting->toArray(),
            'published_at' => $publishedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->groupColumns($groupId, $partOrder, $partReleased)));
    }

    /**
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, buttons: LinkButtons, title: string, titleFormatting: MessageEntities, publishedAt: string}> $parts
     * @param string $groupId the id that binds these parts into one publication,
     *        empty when the rows are separate publications of their own
     */
    public function createPosts(array $parts, string $now, string $groupId = ''): int
    {
        return $this->insertParts('{{%publications_post}}', $parts, $now, true, $groupId);
    }

    /**
     * Whether a group of parts carrying this id is already stored, in whichever
     * of the three blocks its parts happen to rest: a publication that broke
     * into fragments is still the group it was written as.
     */
    public function groupExists(string $groupId): bool
    {
        return (bool)$this->db->createCommand(
            'SELECT EXISTS('
            . 'SELECT 1 FROM ('
            . 'SELECT part_group_id FROM {{%publications_post}}'
            . ' UNION ALL SELECT part_group_id FROM {{%publications_draft}}'
            . ' UNION ALL SELECT part_group_id FROM {{%publications_deleted}}'
            . ') group_homes WHERE group_homes.part_group_id = :group'
            . ')',
            [':group' => $groupId],
        )->queryScalar();
    }

    public function createStagedGroup(array $parts, string $groupId, string $now): int
    {
        return $this->insertParts('{{%publications_post}}', $parts, $now, true, $groupId, true);
    }

    /**
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, buttons: LinkButtons, title: string, titleFormatting: MessageEntities}> $parts
     */
    public function createDrafts(array $parts, string $now, string $groupId = ''): int
    {
        return $this->insertParts('{{%publications_draft}}', $parts, $now, false, $groupId);
    }

    public function findDueForPublishing(string $now): array
    {
        return $this->hydrateAll(
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at, '
            . $this->partColumns()
            . ' FROM {{%publications_post}}'
            . ' WHERE telegram_id IS NULL AND part_released = TRUE AND published_at <= :now'
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

    public function findPartGroup(string $groupId): array
    {
        return $this->hydrateAll(
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at, '
            . $this->partColumns()
            . ' FROM {{%publications_post}} WHERE part_group_id = :group ORDER BY part_order ASC',
            [':group' => $groupId],
        );
    }

    /**
     * Every live part of one publication, in the order of its places, no matter
     * which block it currently lives in. Each row also says which block that
     * is, so the form can badge it: 'channel' for a part that already reached
     * Telegram, 'post', 'draft' or 'deleted' for the rest.
     *
     * @return PublicationData[]
     */
    public function findGroupRows(string $groupId): array
    {
        $front = self::CONTENT_COLUMNS;
        $back = 'created_at, updated_at, part_group_id, part_order, part_released, ';

        return $this->hydrateAll(
            'SELECT * FROM ('
            . 'SELECT ' . $front . 'telegram_id, published_at, NULL::timestamp AS deleted_at, ' . $back
            . "CASE WHEN telegram_id IS NOT NULL THEN 'channel' ELSE 'post' END AS habitat"
            . ' FROM {{%publications_post}}'
            . ' UNION ALL'
            . ' SELECT ' . $front
            . 'NULL::integer AS telegram_id, NULL::timestamp AS published_at, NULL::timestamp AS deleted_at, ' . $back
            . "'draft' AS habitat"
            . ' FROM {{%publications_draft}}'
            . ' UNION ALL'
            . ' SELECT ' . $front . 'telegram_id, published_at, deleted_at, ' . $back
            . "'deleted' AS habitat"
            . ' FROM {{%publications_deleted}}'
            . ') group_part WHERE group_part.part_group_id = :group'
            . ' ORDER BY group_part.part_order ASC',
            [':group' => $groupId],
        );
    }

    public function releasePart(string $groupId, int $partOrder, string $publishedAt, string $now): void
    {
        $changed = $this->db
            ->createCommand()
            ->update('{{%publications_post}}', [
                'published_at' => $publishedAt,
                'updated_at' => $now,
                'part_released' => true,
            ], ['part_group_id' => $groupId, 'part_order' => $partOrder, 'part_released' => false])
            ->execute();

        if ($changed !== 1) {
            throw new InvalidArgumentException('Часть уже поставлена в очередь.');
        }
    }

    public function findPost(int $id): ?PublicationData
    {
        return $this->hydrateOne(
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at, '
            . $this->partColumns()
            . ' FROM {{%publications_post}} WHERE id = :id',
            [':id' => $id],
        );
    }

    public function findDraft(int $id): ?PublicationData
    {
        return $this->hydrateOne(
            'SELECT ' . self::CONTENT_COLUMNS . 'NULL AS telegram_id, NULL AS published_at, created_at, updated_at, '
            . $this->awayPartColumns() . $this->partGroupTotal('publications_draft')
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
        LinkButtons $buttons,
        string $title,
        MessageEntities $titleFormatting,
        ?int $partOrder = null,
    ): void {
        $columns = [
            'text' => $text,
            'image_urls' => $imageUrls,
            'formatting' => $formatting->toArray(),
            'button' => $buttons->toArray(),
            'title' => $title,
            'title_formatting' => $titleFormatting->toArray(),
            'published_at' => $publishedAt,
            'updated_at' => $now,
        ];

        if ($partOrder !== null) {
            $columns['part_order'] = $partOrder;
        }

        $this->db
            ->createCommand()
            ->update('{{%publications_post}}', $columns, ['id' => $id])
            ->execute();
    }

    public function updateDraft(
        int $id,
        string $text,
        array $imageUrls,
        string $now,
        MessageEntities $formatting,
        LinkButtons $buttons,
        string $title,
        MessageEntities $titleFormatting,
        ?int $partOrder = null,
    ): void {
        $columns = [
            'text' => $text,
            'image_urls' => $imageUrls,
            'formatting' => $formatting->toArray(),
            'button' => $buttons->toArray(),
            'title' => $title,
            'title_formatting' => $titleFormatting->toArray(),
            'updated_at' => $now,
        ];

        if ($partOrder !== null) {
            $columns['part_order'] = $partOrder;
        }

        $this->db
            ->createCommand()
            ->update('{{%publications_draft}}', $columns, ['id' => $id])
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
        return $this->insertReturningId('{{%publications_post}}', array_merge([
            'telegram_id' => $post->telegramId,
            'text' => $post->text,
            'image_urls' => $post->imageUrls,
            'formatting' => $post->formatting->toArray(),
            'button' => $post->buttons->toArray(),
            'title' => $post->title,
            'title_formatting' => $post->titleFormatting->toArray(),
            'published_at' => $post->publishedAt ?? $now,
            'created_at' => $post->createdAt,
            'updated_at' => $now,
        ], $this->partCarriage($post)));
    }

    public function insertDraftWithHistory(PublicationData $draft, string $now): int
    {
        return $this->insertReturningId('{{%publications_draft}}', array_merge([
            'text' => $draft->text,
            'image_urls' => $draft->imageUrls,
            'formatting' => $draft->formatting->toArray(),
            'button' => $draft->buttons->toArray(),
            'title' => $draft->title,
            'title_formatting' => $draft->titleFormatting->toArray(),
            'created_at' => $draft->createdAt,
            'updated_at' => $now,
        ], $this->partCarriage($draft)));
    }

    public function archiveEdited(PublicationData $post, string $now): void
    {
        $columns = [
            'telegram_id' => $post->telegramId,
            'text' => $post->text,
            'image_urls' => $post->imageUrls,
            'formatting' => $post->formatting->toArray(),
            'button' => $post->buttons->toArray(),
            'title' => $post->title,
            'title_formatting' => $post->titleFormatting->toArray(),
            'published_at' => $post->publishedAt ?? $now,
            'created_at' => $post->createdAt,
            'updated_at' => $now,
            'edited_at' => null,
        ];

        // The archive carries the group and the place of a part but not the state of
        // its queue: a row that reached the channel never waits there again.
        if (isset($post->partGroupId) && $post->partGroupId !== '' && isset($post->partOrder)) {
            $columns['part_group_id'] = $post->partGroupId;
            $columns['part_order'] = $post->partOrder;
        }

        $this->db
            ->createCommand()
            ->insert('{{%publications_edited}}', $columns)
            ->execute();
    }

    public function allDeleted(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array
    {
        return $this->page(
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at, deleted_at, '
            . $this->awayPartColumns() . $this->partGroupTotal('publications_deleted')
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
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at, deleted_at, '
            . $this->awayPartColumns() . $this->partGroupTotal('publications_deleted')
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
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at'
            . ' FROM {{%publications_deleted}}'
            . ' WHERE deleted_at IS NULL'
            . ' ORDER BY id ASC',
        );
    }

    public function insertDeletedWithHistory(PublicationData $record, string $now): int
    {
        return $this->insertReturningId('{{%publications_deleted}}', array_merge([
            'telegram_id' => $record->telegramId,
            'text' => $record->text,
            'image_urls' => $record->imageUrls,
            'formatting' => $record->formatting->toArray(),
            'button' => $record->buttons->toArray(),
            'title' => $record->title,
            'title_formatting' => $record->titleFormatting->toArray(),
            'published_at' => $record->publishedAt,
            'created_at' => $record->createdAt,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $this->partCarriage($record)));
    }

    /**
     * The row a record leaves behind when it is taken away of the channel: the
     * message itself, with the id the channel knows it by and an empty
     * deleted_at, which is what makes the periodic task delete it. No group
     * columns go in — the part lives on in the block it moved to, and a second
     * row of the same group and place would come back into the form as a second
     * part.
     */
    public function insertChannelDeletionTicket(PublicationData $record, string $now): int
    {
        return $this->insertReturningId('{{%publications_deleted}}', [
            'telegram_id' => $record->telegramId,
            'text' => $record->text,
            'image_urls' => $record->imageUrls,
            'formatting' => $record->formatting->toArray(),
            'button' => $record->buttons->toArray(),
            'title' => $record->title,
            'title_formatting' => $record->titleFormatting->toArray(),
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
            'SELECT ' . self::CONTENT_COLUMNS . 'telegram_id, published_at, created_at, updated_at'
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
     * A group id turns those rows into the parts of one publication: they keep
     * their order and belong to each other wherever they later move. A staged
     * group sends only its first part into the queue and holds the rest back;
     * every other group stands released, and so does a draft, which has no
     * queue of its own.
     *
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, buttons: LinkButtons, title: string, titleFormatting: MessageEntities, publishedAt?: string}> $parts
     */
    private function insertParts(
        string $table,
        array $parts,
        string $now,
        bool $scheduled,
        string $groupId = '',
        bool $staged = false,
    ): int {
        $ids = [];
        $transaction = $this->db->beginTransaction();
        try {
            foreach (array_values($parts) as $index => $part) {
                $columns = [
                    'text' => $part['text'],
                    'image_urls' => $part['imageUrls'],
                    'formatting' => $part['formatting']->toArray(),
                    'button' => $part['buttons']->toArray(),
                    'title' => $part['title'],
                    'title_formatting' => $part['titleFormatting']->toArray(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($scheduled) {
                    $columns['published_at'] = $part['publishedAt'];
                }

                if ($groupId !== '') {
                    $columns['part_group_id'] = $groupId;
                    $columns['part_order'] = $index + 1;
                    $columns['part_released'] = !$staged || $index === 0;
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
     * The group columns a part carries with it when it moves from one table to
     * another: leaving the posts table for the drafts or the deleted list never
     * stops a row from being the same part of the same publication.
     *
     * @return array<string, mixed>
     */
    private function partCarriage(PublicationData $record): array
    {
        if ($record->partGroupId === null || $record->partOrder === null) {
            return [];
        }

        return $this->groupColumns($record->partGroupId, $record->partOrder, $record->partReleased);
    }

    /**
     * The three columns that bind a row written from scratch to the group of
     * parts it joins: which publication it belongs to, which of its parts this
     * one is, and whether the queue may already take it. A row written without a
     * group id says nothing of a group at all.
     *
     * @return array<string, mixed>
     */
    private function groupColumns(string $groupId, int $partOrder, bool $partReleased): array
    {
        if ($groupId === '') {
            return [];
        }

        return [
            'part_group_id' => $groupId,
            'part_order' => $partOrder,
            'part_released' => $partReleased,
        ];
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
     * The columns that say which group of separately published parts a row
     * belongs to, whether the queue may already take it, and whether it is the
     * next one waiting: a part becomes releasable only once the part right
     * above it has reached the channel. Rows outside a group are released from
     * the start, so the last of these reads false for them.
     */
    private function partColumns(): string
    {
        return 'part_group_id, part_order, part_released, '
            . 'CASE WHEN NOT part_released AND EXISTS ('
            . 'SELECT 1 FROM {{%publications_post}} previous WHERE previous.part_group_id = {{%publications_post}}.part_group_id '
            . 'AND previous.part_order = {{%publications_post}}.part_order - 1 '
            . 'AND previous.telegram_id IS NOT NULL'
            . ') THEN TRUE ELSE FALSE END AS part_releasable, '
            . $this->partGroupTotal('publications_post');
    }

    /**
     * The group columns of the tables that hold no queue: which publication a
     * row is a part of, which place it holds, and whether the queue may already
     * take it — the flag travels with the row, so a part that was held back
     * comes back to the posts table still held back.
     */
    private function awayPartColumns(): string
    {
        return 'part_group_id, part_order, part_released, ';
    }

    /**
     * How many parts the publication of this row has, counted over every block
     * a part can live in: moving between them never stops a row from being a
     * part. A row outside a group has no total.
     */
    private function partGroupTotal(string $table): string
    {
        $group = '{{%' . $table . '}}.part_group_id';

        return 'CASE WHEN ' . $group . ' IS NULL THEN NULL ELSE ('
            . 'SELECT COUNT(*) FROM {{%publications_post}} post_part'
            . ' WHERE post_part.part_group_id = ' . $group . ')'
            . ' + (SELECT COUNT(*) FROM {{%publications_draft}} draft_part'
            . ' WHERE draft_part.part_group_id = ' . $group . ')'
            . ' + (SELECT COUNT(*) FROM {{%publications_deleted}} deleted_part'
            . ' WHERE deleted_part.part_group_id = ' . $group . ')'
            . ' END AS part_group_total';
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

        return self::toData($row);
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

        return array_map(self::toData(...), $rows);
    }

    /**
     * The row of any of the three publication tables as a DTO. The group
     * columns are read when the query carries them: `part_releasable`,
     * `part_group_total` and `habitat` are derived in SQL rather than stored,
     * so a plain read of a table has none of them and the DTO falls back to
     * what a row outside a group says about itself.
     *
     * @param array<string, mixed> $row
     */
    private static function toData(array $row): PublicationData
    {
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
            LinkButtons::fromStored($row['button']),
            (string)$row['title'],
            ($row['part_group_id'] ?? null) === null ? null : (string)$row['part_group_id'],
            ($row['part_order'] ?? null) === null ? null : (int)$row['part_order'],
            !array_key_exists('part_released', $row) || filter_var($row['part_released'], FILTER_VALIDATE_BOOLEAN),
            array_key_exists('part_releasable', $row) && filter_var($row['part_releasable'], FILTER_VALIDATE_BOOLEAN),
            array_key_exists('part_group_total', $row) && $row['part_group_total'] !== null
                ? (int)$row['part_group_total']
                : null,
            array_key_exists('habitat', $row) && $row['habitat'] !== null ? (string)$row['habitat'] : null,
            titleFormatting: MessageEntities::fromStored($row['title_formatting']),
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
