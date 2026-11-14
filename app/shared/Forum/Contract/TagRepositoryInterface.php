<?php

declare(strict_types=1);

namespace app\shared\Forum\Contract;

/**
 * Storage boundary of the tag pass over forum sections.
 */
interface TagRepositoryInterface
{
    /**
     * Tries to acquire an exclusive run lock for the pass.
     * Returns false when another tag pass is already running.
     */
    public function acquireLock(string $code): bool;

    public function releaseLock(string $code): void;

    /**
     * Forum sections the pass reads titles from, optionally narrowed to an
     * id range and capped by a row count.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function forums(?int $from = null, ?int $to = null, ?int $limit = null): array;

    /**
     * Writes the tags of one forum section: inserts missing tag rows, links
     * them to the forum and drops links the title no longer produces.
     *
     * @param string[] $tagNames canonical lower-case tag names
     * @return array{tags_created: int, links_created: int}
     */
    public function saveForumTags(int $forumId, array $tagNames, string $now): array;
}
