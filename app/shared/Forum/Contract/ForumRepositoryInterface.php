<?php

declare(strict_types=1);

namespace app\shared\Forum\Contract;

/**
 * Storage boundary for the forum parser.
 */
interface ForumRepositoryInterface extends ForumPublicationMapGatewayInterface
{
    public function activeConfig(string $code): ?array;

    /**
     * The shared tunables of every parser (timeouts, retries, page cap, login
     * address, site timezone) as the columns of one parser_config row, or null
     * while the table holds no row at all.
     */
    public function parserTunables(): ?array;

    /**
     * Every parser_config row, the entity ranges the scans walk.
     */
    public function parserRows(): array;

    /**
     * Stores the shared tunables and the entity ranges in one transaction:
     * a rejected range never leaves the timeouts half written.
     *
     * @param array<string, mixed> $tunables
     * @param array<int, array<string, mixed>> $rows
     */
    public function saveParserSettings(array $tunables, array $rows, string $now): void;

    public function markRun(string $code, string $time): void;

    /**
     * Tries to acquire an exclusive run lock for the config.
     * Returns false when another scan process is already running.
     */
    public function acquireLock(string $code): bool;

    public function releaseLock(string $code): void;

    /**
     * Upserts the topic and its author. Returns true when a new row was inserted.
     */
    public function save(\app\shared\Forum\Dto\TopicData $topic, string $now): bool;

    /**
     * Upserts the posts of one topic page together with their authors.
     * Returns the number of newly inserted post rows.
     */
    public function savePosts(array $posts, string $now): int;

    /**
     * Ids of topics that exist in the topic table and are not login-required stubs.
     *
     * @return int[]
     */
    public function existingTopicIds(int $fromId, int $toId): array;

    /**
     * Upserts a member profile parsed from the memberlist pages.
     * Returns true when a new row was inserted.
     */
    public function saveMemberProfile(\app\shared\Forum\Dto\MemberData $member, string $now): bool;

    /**
     * Latest accessible topics (login_required = false) sorted by
     * published_at descending, $topicLimit rows read from $topicOffset,
     * each with its own posts (at most $postLimit, posted_at descending)
     * and the number of posts its whole discussion has under the filters
     * the page runs by.
     * With $withImagesOnly = true only topics/posts having non-empty
     * image_urls are returned. With $withPostsOnly = true only topics
     * having at least one post are returned, and every post the shown
     * topic has is returned along with it, processed ones included;
     * otherwise the post lists keep only the unprocessed ones. With
     * $imagesCount > 0 only topics/posts having exactly $imagesCount
     * images are returned. A link is an address of the record's own text:
     * with $withLinksOnly = true only topics that carry one themselves or
     * have a post that does are returned, and their post lists keep those
     * posts; with $linksCount > 0 only topics/posts whose text carries
     * exactly $linksCount addresses are returned.
     *
     * $oldestTopicFirst and $oldestPostFirst read their own field from
     * the other end: the limit then cuts the oldest topics / the oldest
     * posts of a topic rather than the newest ones.
     *
     * $textOrder of 'asc' or 'desc' stands the topics up by the length of
     * their own text instead of their dates — 'asc' the shortest first,
     * 'desc' the longest — and leaves the posts of each discussion in the
     * order they were asked for. Any other value keeps the date order.
     *
     * @return array<int, array{topic: \app\shared\Forum\Dto\TopicData, posts: \app\shared\Forum\Dto\PostData[], postsTotal: int}>
     */
    public function latestTopicsWithPosts(int $topicLimit, int $postLimit, bool $withImagesOnly = false, bool $withPostsOnly = false, int $imagesCount = 0, bool $withLinksOnly = false, int $linksCount = 0, bool $oldestTopicFirst = false, bool $oldestPostFirst = false, int $topicOffset = 0, string $textOrder = ''): array;

    /**
     * How many topics the forum block of these filters is made of, which is
     * how far its scroll may keep loading.
     */
    public function countTopics(bool $withImagesOnly = false, bool $withPostsOnly = false, int $imagesCount = 0, bool $withLinksOnly = false, int $linksCount = 0): int;

    /**
     * The next page of one discussion: $limit posts read from $offset in the
     * order the block shows them, plus how many posts the topic has under
     * the filters of the page. The two queries of latestTopicsWithPosts cut
     * their first page from the same selection, so the pages of a discussion
     * never repeat or skip a post the reader has already seen.
     *
     * @return array{posts: \app\shared\Forum\Dto\PostData[], total: int}
     */
    public function topicPosts(int $topicId, int $limit, int $offset, bool $withImagesOnly = false, bool $withPostsOnly = false, int $imagesCount = 0, bool $withLinksOnly = false, int $linksCount = 0, bool $oldestPostFirst = false): array;

    /**
     * Every post the table holds for one topic, in the order the block shows
     * them. Unlike topicPosts() there is no page to cut and no filter to drop
     * a row: a thread that goes to the form is made of all of its posts,
     * including the ones already published to the channel. The one rule the
     * block keeps is its own — a topic that needs a login has no thread here.
     *
     * @return \app\shared\Forum\Dto\PostData[]
     */
    public function topicThread(int $topicId, bool $oldestPostFirst = false): array;

    /**
     * Marks a forum topic as viewed by the "Просмотрено" button:
     * inserts a publications_topic_map row with an empty telegram_id
     * (an existing row is left untouched). The viewed element is not
     * published to the channel by itself, so the telegram_id stays
     * empty unless a publication created from it is later sent to
     * the channel. When the topic has exactly one post, that post is
     * marked viewed the same way.
     */
    public function markTopicViewed(int $topicId): void;

    /**
     * Marks a forum post as viewed by the "Просмотрено" button:
     * inserts a publications_post_map row with an empty telegram_id
     * (an existing row is left untouched). When the post is the only
     * post of its topic, the topic is marked viewed the same way.
     */
    public function markPostViewed(int $postId): void;
}
