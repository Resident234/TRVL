<?php

declare(strict_types=1);

namespace app\shared\Publications\Contract;

use app\shared\Publications\Dto\PublicationData;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;

/**
 * Storage boundary for channel publications: scheduled posts and drafts.
 */
interface PublicationRepositoryInterface
{
    /**
     * Scheduled and published posts, newest `published_at` first — or oldest
     * first when $oldestFirst asks. A zero limit reads the whole table.
     *
     * @return PublicationData[]
     */
    public function allPosts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array;

    /**
     * Drafts, newest `updated_at` first (oldest first when $oldestFirst
     * asks). A zero limit reads the whole table.
     *
     * @return PublicationData[]
     */
    public function allDrafts(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array;

    /**
     * How many posts the unpaged list holds, so a paged reader knows
     * when it has reached the end.
     */
    public function countPosts(): int;

    /**
     * How many drafts the unpaged list holds.
     */
    public function countDrafts(): int;

    /**
     * Creates a draft from the form data. Returns the new row id.
     *
     * A group id and a place write the row as one part of an existing group,
     * which is how a part the form invented joins the parts it was loaded with.
     * A draft has no queue of its own, so its part stands released.
     */
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
    ): int;

    /**
     * Creates a scheduled (or immediately due) post from the form data. Returns
     * the new row id.
     *
     * A group id and a place write the row as one part of an existing group;
     * $partReleased says whether the queue may take it at once or it waits for
     * the part above it, which is what keeps a new part from slipping ahead.
     */
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
    ): int;

    /**
     * Creates the parts of one long publication as separate scheduled
     * posts in a single transaction: either every part is stored or none
     * of them is. The parts arrive in channel order, each with its own
     * publication time. Returns the id of the first part, the record a
     * forum link is bound to.
     *
     * A group id binds the rows into one publication that keeps being a
     * group wherever its parts later move; without one every part is a
     * publication of its own. A plain group is released to the queue in
     * full — only createStagedGroup() holds the lower parts back.
     *
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, buttons: LinkButtons, title: string, titleFormatting: MessageEntities, publishedAt: string}> $parts
     */
    public function createPosts(array $parts, string $now, string $groupId = ''): int;

    /**
     * Creates the parts of one long publication as separate drafts in a
     * single transaction, with the same all-or-nothing guarantee and the
     * same first part id as createPosts(). A draft has no queue of its
     * own, so every part of it stands released and a later move to the
     * posts table sends it out at its time.
     *
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, buttons: LinkButtons, title: string, titleFormatting: MessageEntities}> $parts
     */
    public function createDrafts(array $parts, string $now, string $groupId = ''): int;

    /**
     * @return PublicationData[] posts with an empty telegram_id and a due
     * publication time, ordered from the smallest id to the biggest one
     */
    public function findDueForPublishing(string $now): array;

    /**
     * Stores the Telegram message id of a successfully published post
     * and corrects published_at to the actual send time.
     */
    public function storeTelegramId(int $id, int $telegramId, string $publishedAt, string $now): void;

    /**
     * Whether a group of parts carrying this id is already stored.
     */
    public function groupExists(string $groupId): bool;

    /**
     * Stores the parts of one publication as an ordered group: only its first
     * part enters the queue, the rest wait until they are released one by one.
     *
     * @param array<int, array{text: string, imageUrls: string[], formatting: MessageEntities, buttons: LinkButtons, title: string, titleFormatting: MessageEntities, publishedAt: string}> $parts
     *
     * @return int the id of the first part
     */
    public function createStagedGroup(array $parts, string $groupId, string $now): int;

    /**
     * @return PublicationData[] the parts of a group, ordered by their number
     */
    public function findPartGroup(string $groupId): array;

    /**
     * Every live part of a group, ordered by its number, read from all three
     * blocks at once: a publication keeps belonging to itself while its parts
     * lie in the posts list, in drafts or in the deleted list. Each row says
     * where it lives ('channel', 'post', 'draft' or 'deleted').
     *
     * @return PublicationData[]
     */
    public function findGroupRows(string $groupId): array;

    /**
     * Lets one part of a group into the queue with the given publication time.
     */
    public function releasePart(string $groupId, int $partOrder, string $publishedAt, string $now): void;

    /**
     * Finds a post by id.
     */
    public function findPost(int $id): ?PublicationData;

    /**
     * Finds a draft by id.
     */
    public function findDraft(int $id): ?PublicationData;

    /**
     * Updates a scheduled post: text, image urls, formatting, buttons, title,
     * the marks of the heading and published_at from the form; updated_at is
     * set to $now, created_at stays untouched. A place rewrites the rank of the
     * row inside its group, which is what the form gives it; without one the row
     * keeps its place.
     */
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
    ): void;

    /**
     * Updates a draft: text, image urls, formatting, buttons, title and the
     * marks of the heading from the form; updated_at is set to $now, created_at
     * stays untouched. A place rewrites the rank of the row inside its group,
     * which is what the form gives it; without one the row keeps its place.
     */
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
    ): void;

    /**
     * Deletes a post row and returns it as it was before deletion.
     */
    public function deletePost(int $id): PublicationData;

    /**
     * Deletes a draft row and returns it as it was before deletion.
     */
    public function deleteDraft(int $id): PublicationData;

    /**
     * Inserts a post row preserving the original created_at (used when
     * moving records between the posts, drafts and edited tables).
     * Returns the new row id.
     */
    public function insertPostWithHistory(PublicationData $post, string $now): int;

    /**
     * Inserts a draft row preserving the original created_at.
     * Returns the new row id.
     */
    public function insertDraftWithHistory(PublicationData $draft, string $now): int;

    /**
     * Archives a published post row into publications_edited with all
     * field values copied; updated_at is set to $now.
     */
    public function archiveEdited(PublicationData $post, string $now): void;

    /**
     * Soft-deleted records, newest `updated_at` first (oldest first when
     * $oldestFirst asks). A zero limit reads the whole table.
     *
     * @return PublicationData[]
     */
    public function allDeleted(int $limit = 0, int $offset = 0, bool $oldestFirst = false): array;

    /**
     * How many soft-deleted records the unpaged list holds.
     */
    public function countDeleted(): int;

    /**
     * Finds a soft-deleted record by id.
     */
    public function findDeleted(int $id): ?PublicationData;

    /**
     * Deletes a publications_deleted row and returns it as it was
     * before deletion (used when restoring a record).
     */
    public function deleteDeleted(int $id): PublicationData;

    /**
     * Inserts a publications_deleted row preserving the original
     * created_at and published_at; updated_at is set to $now,
     * deleted_at stays empty until the periodic task confirms the
     * channel removal. Returns the new row id.
     */
    public function insertDeletedWithHistory(PublicationData $record, string $now): int;

    /**
     * Inserts a publications_deleted row whose only purpose is the message the
     * channel still holds: the same columns as a soft-deleted record, an empty
     * deleted_at, and no group — the part itself lives on in the block it moved
     * to, and a second row claiming its place would be read back as a second
     * part. Returns the new row id.
     */
    public function insertChannelDeletionTicket(PublicationData $record, string $now): int;

    /**
     * @return PublicationData[] soft-deleted posts with an empty
     * deleted_at, i.e. records awaiting removal from the channel,
     * ordered from the smallest id to the biggest one
     */
    public function findPendingChannelDeletion(): array;

    /**
     * Stamps the time a soft-deleted record was actually removed
     * from the channel.
     */
    public function storeDeletedAt(int $id, string $deletedAt): void;

    /**
     * @return PublicationData[] archived edited posts with an empty
     * edited_at, i.e. records awaiting the channel update, ordered
     * from the smallest id to the biggest one
     */
    public function findPendingChannelEdits(): array;

    /**
     * Stamps the time an edited record's message was actually
     * updated in the channel.
     */
    public function storeEditedAt(int $id, string $editedAt): void;
}
