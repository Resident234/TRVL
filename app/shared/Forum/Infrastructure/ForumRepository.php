<?php

declare(strict_types=1);

namespace app\shared\Forum\Infrastructure;

use app\shared\Forum\Contract\ForumPublicationMapGatewayInterface;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Forum\Dto\MemberData;
use app\shared\Forum\Dto\PostData;
use app\shared\Forum\Dto\TopicData;
use PDO;
use yii\db\Connection;
use yii\db\JsonExpression;

/**
 * PostgreSQL storage for the forum parser. All SQL lives here:
 * upper layers receive and return DTOs and plain arrays only.
 */
final class ForumRepository implements ForumRepositoryInterface, ForumPublicationMapGatewayInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function activeConfig(string $code): ?array
    {
        $row = $this->db
            ->createCommand('SELECT * FROM {{%parser_config}} WHERE code = :code AND is_active = TRUE LIMIT 1')
            ->bindValues([':code' => $code])
            ->queryOne(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markRun(string $code, string $time): void
    {
        $this->db
            ->createCommand('UPDATE {{%parser_config}} SET last_run_at = :time, updated_at = :time WHERE code = :code')
            ->bindValues([':time' => $time, ':code' => $code])
            ->execute();
    }

    public function parserTunables(): ?array
    {
        $row = $this->db
            ->createCommand(
                'SELECT http_timeout, http_retries, http_delay_microseconds, http_max_redirects,'
                . ' http_banned_statuses, login_url, max_pages, source_timezone'
                . ' FROM {{%parser_config}} ORDER BY id LIMIT 1',
            )
            ->queryOne(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function parserRows(): array
    {
        return $this->db
            ->createCommand(
                'SELECT id, code, base_url, t_from, t_to, is_active, last_run_at'
                . ' FROM {{%parser_config}} ORDER BY code',
            )
            ->queryAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $tunables
     * @param array<int, array<string, mixed>> $rows
     */
    public function saveParserSettings(array $tunables, array $rows, string $now): void
    {
        $transaction = $this->db->beginTransaction();
        try {
            $this->db
                ->createCommand(
                    'UPDATE {{%parser_config}} SET http_timeout = :timeout, http_retries = :retries,'
                    . ' http_delay_microseconds = :delay, http_max_redirects = :redirects,'
                    . ' http_banned_statuses = :statuses, login_url = :login_url,'
                    . ' max_pages = :max_pages, source_timezone = :timezone, updated_at = :now',
                )
                ->bindValues([
                    ':timeout' => (int)$tunables['http_timeout'],
                    ':retries' => (int)$tunables['http_retries'],
                    ':delay' => (int)$tunables['http_delay_microseconds'],
                    ':redirects' => (int)$tunables['http_max_redirects'],
                    ':statuses' => (string)$tunables['http_banned_statuses'],
                    ':login_url' => (string)$tunables['login_url'],
                    ':max_pages' => (int)$tunables['max_pages'],
                    ':timezone' => (string)$tunables['source_timezone'],
                    ':now' => $now,
                ])
                ->execute();

            foreach ($rows as $row) {
                $this->db
                    ->createCommand(
                        'UPDATE {{%parser_config}} SET base_url = :base_url, t_from = :t_from,'
                        . ' t_to = :t_to, is_active = :is_active, updated_at = :now WHERE id = :id',
                    )
                    ->bindValues([
                        ':base_url' => (string)$row['baseUrl'],
                        ':t_from' => (int)$row['tFrom'],
                        ':t_to' => (int)$row['tTo'],
                        ':is_active' => $row['isActive'] === true,
                        ':now' => $now,
                        ':id' => (int)$row['id'],
                    ])
                    ->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * Session-level PostgreSQL advisory lock: held until the process
     * finishes or the DB session drops, so a crashed run never blocks
     * the next launch.
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
     * Upserts the topic and its author. Returns true when a new row was inserted.
     */
    public function save(TopicData $topic, string $now): bool
    {
        $transaction = $this->db->beginTransaction();
        try {
            $exists = $this->db
                ->createCommand('SELECT 1 FROM {{%topic}} WHERE id = :id')
                ->bindValue(':id', $topic->id)
                ->queryScalar() !== false;
            if ($topic->author !== null) {
                $this->saveMember($topic->author, $now);
            }
            $this->saveTopic($topic, $now);
            $transaction->commit();
            return !$exists;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Upserts the posts of one topic page together with their authors
     * in a single transaction. Returns the number of newly inserted posts.
     */
    public function savePosts(array $posts, string $now): int
    {
        $inserted = 0;
        $transaction = $this->db->beginTransaction();
        try {
            foreach ($posts as $post) {
                if (!$post instanceof PostData) {
                    continue;
                }
                if ($post->author !== null) {
                    $this->saveMember($post->author, $now);
                }
                $exists = $this->db
                    ->createCommand('SELECT 1 FROM {{%post}} WHERE id = :id')
                    ->bindValue(':id', $post->id)
                    ->queryScalar() !== false;
                $this->savePost($post, $now);
                if (!$exists) {
                    $inserted++;
                }
            }
            $transaction->commit();
            return $inserted;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @return int[]
     */
    public function existingTopicIds(int $fromId, int $toId): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT id FROM {{%topic}} WHERE id BETWEEN :from AND :to AND login_required = FALSE ORDER BY id'
            )
            ->bindValues([':from' => $fromId, ':to' => $toId])
            ->queryColumn();
        return array_map(intval(...), $rows);
    }

    /**
     * How many addresses the text of a record carries. The same notion the
     * page filter reads the stored number by: a bare http(s) address of the
     * text, so an anchor hidden under a word is not one.
     */
    private function linksCountOf(string $text): int
    {
        return (int)preg_match_all('~https?://~', $text);
    }

    /**
     * How many characters the text of a record counts — the number the sort of
     * the block orders its topics by. The database counts the same way
     * (char_length over the text), so a row the parser wrote and a row the
     * migration of the column filled land in the same place of the list.
     */
    private function textLengthOf(string $text): int
    {
        return mb_strlen($text);
    }

    private function savePost(PostData $post, string $now): void
    {
        $this->db->createCommand()->upsert(
            '{{%post}}',
            [
                'id' => $post->id,
                'topic_id' => $post->topicId,
                'author_id' => $post->author?->id,
                'number' => $post->number,
                'title' => $post->title,
                'posted_at' => $post->postedAt,
                'content_html' => $post->contentHtml,
                'content_text' => $post->contentText,
                'source_url' => $post->sourceUrl,
                'image_urls' => new JsonExpression($post->imageUrls),
                'links_count' => $this->linksCountOf($post->contentText),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'topic_id' => $post->topicId,
                'author_id' => $post->author?->id,
                'number' => $post->number,
                'title' => $post->title,
                'posted_at' => $post->postedAt,
                'content_html' => $post->contentHtml,
                'content_text' => $post->contentText,
                'source_url' => $post->sourceUrl,
                'image_urls' => new JsonExpression($post->imageUrls),
                'links_count' => $this->linksCountOf($post->contentText),
                'updated_at' => $now,
            ]
        )->execute();
    }

    private function saveTopic(TopicData $topic, string $now): void
    {
        $this->db->createCommand()->upsert(
            '{{%topic}}',
            [
                'id' => $topic->id,
                'source_url' => $topic->sourceUrl,
                'title' => $topic->title,
                'published_at' => $topic->publishedAt,
                'content_html' => $topic->contentHtml,
                'content_text' => $topic->contentText,
                'image_urls' => new JsonExpression($topic->imageUrls),
                'links_count' => $this->linksCountOf($topic->contentText),
                'text_length' => $this->textLengthOf($topic->contentText),
                'author_id' => $topic->author?->id,
                'login_required' => $topic->loginRequired,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'source_url' => $topic->sourceUrl,
                'title' => $topic->title,
                'published_at' => $topic->publishedAt,
                'content_html' => $topic->contentHtml,
                'content_text' => $topic->contentText,
                'image_urls' => new JsonExpression($topic->imageUrls),
                'links_count' => $this->linksCountOf($topic->contentText),
                'text_length' => $this->textLengthOf($topic->contentText),
                'author_id' => $topic->author?->id,
                'login_required' => $topic->loginRequired,
                'updated_at' => $now,
            ]
        )->execute();
    }

    private function saveMember(MemberData $member, string $now): void
    {
        $this->db->createCommand()->upsert(
            '{{%member}}',
            [
                'id' => $member->id,
                'profile_url' => $member->profileUrl,
                'name' => $member->name,
                'avatar_url' => $member->avatarUrl,
                'rank_name' => $member->rankName,
                'messages_count' => $member->messagesCount,
                'registered_on' => $member->registeredOn,
                'last_visit_at' => $member->lastVisitAt,
                'photos_count' => $member->photosCount,
                'city' => $member->city,
                'thanks_given_count' => $member->thanksGivenCount,
                'thanks_received_count' => $member->thanksReceivedCount,
                'age' => $member->age,
                'countries_count' => $member->countriesCount,
                'reports_count' => $member->reportsCount,
                'gender' => $member->gender,
                'profile_login_required' => $member->profileLoginRequired,
                'raw_data' => new JsonExpression($member->rawData),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'profile_url' => $member->profileUrl,
                'name' => $member->name,
                'avatar_url' => $member->avatarUrl,
                'rank_name' => $member->rankName,
                'messages_count' => $member->messagesCount,
                'registered_on' => $member->registeredOn,
                'last_visit_at' => $member->lastVisitAt,
                'photos_count' => $member->photosCount,
                'city' => $member->city,
                'thanks_given_count' => $member->thanksGivenCount,
                'thanks_received_count' => $member->thanksReceivedCount,
                'age' => $member->age,
                'countries_count' => $member->countriesCount,
                'reports_count' => $member->reportsCount,
                'gender' => $member->gender,
                'profile_login_required' => $member->profileLoginRequired,
                'raw_data' => new JsonExpression($member->rawData),
                'updated_at' => $now,
            ]
        )->execute();
    }

    /**
     * Upserts a member profile parsed from the memberlist pages. Unlike
     * saveMember() (which overwrites everything with post-block values),
     * this merges: fields the memberlist does not provide (thanks, countries,
     * reports) keep their stored values. Returns true for a new row.
     */
    public function saveMemberProfile(MemberData $member, string $now): bool
    {
        $exists = $this->db
            ->createCommand('SELECT 1 FROM {{%member}} WHERE id = :id')
            ->bindValue(':id', $member->id)
            ->queryScalar() !== false;

        $values = [
            'profile_url' => $member->profileUrl,
            'name' => $member->name,
            'avatar_url' => $member->avatarUrl,
            'rank_name' => $member->rankName,
            'messages_count' => $member->messagesCount,
            'registered_on' => $member->registeredOn,
            'last_visit_at' => $member->lastVisitAt,
            'photos_count' => $member->photosCount,
            'city' => $member->city,
            'age' => $member->age,
            'gender' => $member->gender,
            'profile_login_required' => $member->profileLoginRequired,
            'updated_at' => $now,
        ];
        $this->db->createCommand()->upsert(
            '{{%member}}',
            array_merge($values, [
                'id' => $member->id,
                'raw_data' => new JsonExpression($member->rawData),
                'created_at' => $now,
            ]),
            array_merge($values, ['raw_data' => new JsonExpression($member->rawData)])
        )->execute();
        return !$exists;
    }

    /**
     * Latest accessible topics (login_required = false) sorted by
     * published_at descending, $topicLimit rows of them starting at
     * $topicOffset. Each topic carries its own posts (at most $postLimit,
     * posted_at descending, full field values) with author data hydrated
     * from the joined member rows, and how many posts the whole discussion
     * has under the filters the page is showing.
     *
     * Publication filter: only topics without a publications_topic_map
     * record are listed, plus mapped (viewed/published) topics that
     * still have at least one post without a publications_post_map
     * record. Unless the post filter below widens the lists, they
     * contain only posts without a map record.
     *
     * With $withImagesOnly = true only topics that have images of their
     * own or at least one post with images are returned; their post
     * lists are also reduced to posts with images only.
     *
     * With $withPostsOnly = true only topics that have at least one
     * post in the post table are returned, and each returned topic
     * carries all of its posts: once the topic itself or one of its
     * posts is still unprocessed, the whole discussion is shown, so
     * processed posts are kept in the list with their status.
     *
     * With $imagesCount > 0 only topics/posts having exactly $imagesCount images are returned.
     *
     * With $withLinksOnly = true only topics whose own text carries an address
     * or that have at least one post whose text does are returned; their post
     * lists are also reduced to the posts that carry an address. A link is an
     * address counted by links_count, which the database keeps over
     * content_text: an address a post only links to with its own words is not
     * part of its text and is not counted.
     *
     * With $linksCount > 0 only topics/posts whose text carries exactly
     * $linksCount addresses are returned.
     *
     * $oldestTopicFirst and $oldestPostFirst read their own date field from
     * the other end. Both orders keep a record without a date at the very
     * end and break ties on the row id, and because the limit of a topic's
     * posts is taken in the same order, reversing it hands over the oldest
     * $postLimit posts of the discussion instead of the newest ones.
     *
     * $textOrder of 'asc' or 'desc' stands the topics up by the length of
     * their own text — 'asc' the shortest first, 'desc' the longest — and
     * takes the list out of the date order, so $oldestTopicFirst keeps
     * saying only in which order the posts of each discussion read. Any
     * other value leaves the topics in the order of their dates.
     *
     * @return array<int, array{topic: TopicData, posts: PostData[], postsTotal: int}>
     */
    public function latestTopicsWithPosts(int $topicLimit, int $postLimit, bool $withImagesOnly = false, bool $withPostsOnly = false, int $imagesCount = 0, bool $withLinksOnly = false, int $linksCount = 0, bool $oldestTopicFirst = false, bool $oldestPostFirst = false, int $topicOffset = 0, string $textOrder = ''): array
    {
        $topicOrder = $oldestTopicFirst ? 'ASC' : 'DESC';
        $postOrder = $oldestPostFirst ? 'ASC' : 'DESC';
        $topicRows = $this->db
            ->createCommand(
                'SELECT t.id, t.source_url, t.title, t.published_at, t.content_html, t.content_text, t.image_urls,'
                . ' m.id AS author_id, m.profile_url AS author_profile_url, m.name AS author_name,'
                . ' m.avatar_url AS author_avatar_url, m.rank_name AS author_rank_name,'
                . ' ptm.topic_id AS publication_map_id, ptm.telegram_id AS publication_telegram_id'
                . ' FROM {{%topic}} t'
                . ' LEFT JOIN {{%member}} m ON m.id = t.author_id'
                . ' LEFT JOIN {{%publications_topic_map}} ptm ON ptm.topic_id = t.id'
                . ' WHERE t.login_required = FALSE' . $this->topicFilterSql($withImagesOnly, $withPostsOnly, $imagesCount, $withLinksOnly, $linksCount)
                . ' ORDER BY ' . $this->topicOrderSql($topicOrder, $textOrder)
                . ' LIMIT :limit OFFSET :offset'
            )
            ->bindValue(':limit', $topicLimit)
            ->bindValue(':offset', max(0, $topicOffset))
            ->queryAll(PDO::FETCH_ASSOC);

        if ($topicRows === []) {
            return [];
        }

        $topicIds = array_map(static fn (array $row): int => (int)$row['id'], $topicRows);
        $postRows = $this->db
            ->createCommand(
                'SELECT p.id, p.topic_id, p.author_id, p.number, p.title, p.posted_at, p.content_html, p.content_text, p.source_url, p.image_urls, p.topic_post_total,'
                . ' m.profile_url AS author_profile_url, m.name AS author_name,'
                . ' m.avatar_url AS author_avatar_url, m.rank_name AS author_rank_name,'
                . ' ppm.post_id AS publication_map_id, ppm.telegram_id AS publication_telegram_id'
                . ' FROM ('
                . ' SELECT bp.id, bp.topic_id, bp.author_id, bp.number, bp.title, bp.posted_at, bp.content_html, bp.content_text, bp.source_url, bp.image_urls,'
                . ' ROW_NUMBER() OVER (PARTITION BY bp.topic_id ORDER BY bp.posted_at ' . $postOrder . ' NULLS LAST, bp.id ' . $postOrder . ') AS rn,'
                . ' COUNT(*) OVER (PARTITION BY bp.topic_id) AS topic_post_total'
                . ' FROM {{%post}} bp'
                . ' WHERE bp.topic_id IN (' . implode(',', $topicIds) . ')'
                . $this->unprocessedPostFilterSql($withPostsOnly)
                . $this->postImageFilterSql($withImagesOnly, $imagesCount, 'bp')
                . $this->postLinkFilterSql($withLinksOnly, $linksCount, 'bp')
                . ' ) p'
                . ' LEFT JOIN {{%member}} m ON m.id = p.author_id'
                . ' LEFT JOIN {{%publications_post_map}} ppm ON ppm.post_id = p.id'
                . ' WHERE p.rn <= :postLimit'
                . ' ORDER BY p.topic_id, p.posted_at ' . $postOrder . ' NULLS LAST, p.id ' . $postOrder
            )
            ->bindValue(':postLimit', $postLimit)
            ->queryAll(PDO::FETCH_ASSOC);

        $postsByTopic = [];
        $totalsByTopic = [];
        foreach ($postRows as $row) {
            $topicId = (int)$row['topic_id'];
            $postsByTopic[$topicId][] = $this->hydratePostRow($row);
            $totalsByTopic[$topicId] = (int)$row['topic_post_total'];
        }

        $result = [];
        foreach ($topicRows as $row) {
            $topicId = (int)$row['id'];
            $result[] = [
                'topic' => $this->hydrateTopicRow($row),
                'posts' => $postsByTopic[$topicId] ?? [],
                'postsTotal' => $totalsByTopic[$topicId] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * How many topics the filters of the page leave for the forum block: the
     * very selection latestTopicsWithPosts pages through, counted without its
     * limit, so the scroll knows when the list has run out.
     */
    public function countTopics(bool $withImagesOnly = false, bool $withPostsOnly = false, int $imagesCount = 0, bool $withLinksOnly = false, int $linksCount = 0): int
    {
        return (int)$this->db
            ->createCommand(
                'SELECT COUNT(*) FROM {{%topic}} t'
                . ' LEFT JOIN {{%publications_topic_map}} ptm ON ptm.topic_id = t.id'
                . ' WHERE t.login_required = FALSE' . $this->topicFilterSql($withImagesOnly, $withPostsOnly, $imagesCount, $withLinksOnly, $linksCount)
            )
            ->queryScalar();
    }

    /**
     * The next page of one discussion: $limit posts read from $offset in the
     * order the block shows them, together with how many posts the topic has
     * under the filters of the page. The numbering runs over the same
     * selection the first page of the discussion was cut from, so a post the
     * reader is looking at is never handed out twice and none is skipped.
     *
     * @return array{posts: PostData[], total: int}
     */
    public function topicPosts(int $topicId, int $limit, int $offset, bool $withImagesOnly = false, bool $withPostsOnly = false, int $imagesCount = 0, bool $withLinksOnly = false, int $linksCount = 0, bool $oldestPostFirst = false): array
    {
        $postOrder = $oldestPostFirst ? 'ASC' : 'DESC';
        $from = max(0, $offset);
        $rows = $this->db
            ->createCommand(
                'SELECT p.id, p.topic_id, p.author_id, p.number, p.title, p.posted_at, p.content_html, p.content_text, p.source_url, p.image_urls, p.total,'
                . ' m.profile_url AS author_profile_url, m.name AS author_name,'
                . ' m.avatar_url AS author_avatar_url, m.rank_name AS author_rank_name,'
                . ' ppm.post_id AS publication_map_id, ppm.telegram_id AS publication_telegram_id'
                . ' FROM ('
                . ' SELECT bp.id, bp.topic_id, bp.author_id, bp.number, bp.title, bp.posted_at, bp.content_html, bp.content_text, bp.source_url, bp.image_urls,'
                . ' ROW_NUMBER() OVER (ORDER BY bp.posted_at ' . $postOrder . ' NULLS LAST, bp.id ' . $postOrder . ') AS rn,'
                . ' COUNT(*) OVER () AS total'
                . ' FROM {{%post}} bp'
                . ' WHERE bp.topic_id = :topicId'
                . $this->unprocessedPostFilterSql($withPostsOnly)
                . $this->postImageFilterSql($withImagesOnly, $imagesCount, 'bp')
                . $this->postLinkFilterSql($withLinksOnly, $linksCount, 'bp')
                . ' ) p'
                . ' LEFT JOIN {{%member}} m ON m.id = p.author_id'
                . ' LEFT JOIN {{%publications_post_map}} ppm ON ppm.post_id = p.id'
                . ' WHERE p.rn > :from AND p.rn <= :to'
                . ' ORDER BY p.posted_at ' . $postOrder . ' NULLS LAST, p.id ' . $postOrder
            )
            ->bindValue(':topicId', $topicId)
            ->bindValue(':from', $from)
            ->bindValue(':to', $from + $limit)
            ->queryAll(PDO::FETCH_ASSOC);

        $posts = [];
        foreach ($rows as $row) {
            $posts[] = $this->hydratePostRow($row);
        }

        // A page past the end carries no row to read the count from, and the
        // reader who asked for it has reached the bottom either way.
        return ['posts' => $posts, 'total' => $rows === [] ? 0 : (int)$rows[0]['total']];
    }

    /**
     * Every post the table holds for one topic, in the order the block shows
     * them. Unlike topicPosts() there is no page to cut and no filter to drop
     * a row: a thread that goes to the form is made of all of its posts,
     * including the ones already published to the channel. The one rule the
     * block keeps is its own — a topic that needs a login has no thread here.
     *
     * @return PostData[]
     */
    public function topicThread(int $topicId, bool $oldestPostFirst = false): array
    {
        $postOrder = $oldestPostFirst ? 'ASC' : 'DESC';
        $rows = $this->db
            ->createCommand(
                'SELECT p.id, p.topic_id, p.author_id, p.number, p.title, p.posted_at, p.content_html, p.content_text, p.source_url, p.image_urls,'
                . ' m.profile_url AS author_profile_url, m.name AS author_name,'
                . ' m.avatar_url AS author_avatar_url, m.rank_name AS author_rank_name,'
                . ' ppm.post_id AS publication_map_id, ppm.telegram_id AS publication_telegram_id'
                . ' FROM {{%post}} p'
                . ' INNER JOIN {{%topic}} t ON t.id = p.topic_id AND t.login_required = FALSE'
                . ' LEFT JOIN {{%member}} m ON m.id = p.author_id'
                . ' LEFT JOIN {{%publications_post_map}} ppm ON ppm.post_id = p.id'
                . ' WHERE p.topic_id = :topicId'
                . ' ORDER BY p.posted_at ' . $postOrder . ' NULLS LAST, p.id ' . $postOrder
            )
            ->bindValue(':topicId', $topicId)
            ->queryAll(PDO::FETCH_ASSOC);

        $posts = [];
        foreach ($rows as $row) {
            $posts[] = $this->hydratePostRow($row);
        }

        return $posts;
    }

    /**
     * The order of the topic list. By default the list reads itself by dates;
     * the length switch of the header puts the text of each topic in front of
     * them and breaks ties on the row id, in the direction it was asked for.
     * The stored text_length is what it orders, so no topic text is counted
     * while the page is read.
     */
    private function topicOrderSql(string $topicOrder, string $textOrder): string
    {
        $lengthOrder = match ($textOrder) {
            'asc' => 'ASC',
            'desc' => 'DESC',
            default => '',
        };

        return $lengthOrder === ''
            ? 't.published_at ' . $topicOrder . ' NULLS LAST, t.id ' . $topicOrder
            : 't.text_length ' . $lengthOrder . ', t.id ' . $lengthOrder;
    }

    /**
     * The rule that lets a topic reach the page: it is unprocessed itself, or
     * one of its posts still is, plus the filters of the block header. Shared
     * by the list of topics and by the count that pages it. The post the rule
     * reads is filtered by the very conditions the list of posts reads, so a
     * topic is never kept on the page by a post the page would hide.
     */
    private function topicFilterSql(bool $withImagesOnly, bool $withPostsOnly, int $imagesCount, bool $withLinksOnly, int $linksCount): string
    {
        return ($withImagesOnly
            ? ' AND (t.image_urls != \'[]\'::jsonb OR EXISTS (SELECT 1 FROM {{%post}} fp WHERE fp.topic_id = t.id AND fp.image_urls != \'[]\'::jsonb))'
            : '')
            . ($withPostsOnly
                ? ' AND EXISTS (SELECT 1 FROM {{%post}} pp WHERE pp.topic_id = t.id)'
                : '')
            . ($imagesCount > 0
                ? ' AND COALESCE(jsonb_array_length(t.image_urls), 0) = ' . $imagesCount
                : '')
            . ($withLinksOnly
                ? ' AND (t.links_count > 0 OR EXISTS (SELECT 1 FROM {{%post}} lp WHERE lp.topic_id = t.id AND lp.links_count > 0))'
                : '')
            . ($linksCount > 0
                ? ' AND t.links_count = ' . $linksCount
                : '')
            . ' AND (ptm.topic_id IS NULL OR EXISTS ('
            . 'SELECT 1 FROM {{%post}} fp'
            . ' WHERE fp.topic_id = t.id'
            . ' AND NOT EXISTS (SELECT 1 FROM {{%publications_post_map}} fpm WHERE fpm.post_id = fp.id)'
            . $this->postImageFilterSql($withImagesOnly, $imagesCount, 'fp')
            . $this->postLinkFilterSql($withLinksOnly, $linksCount, 'fp')
            . '))';
    }

    /**
     * A discussion shows only its unprocessed posts unless the page is
     * filtered down to topics that have any post at all: then the whole
     * thread is kept, processed posts included.
     */
    private function unprocessedPostFilterSql(bool $withPostsOnly): string
    {
        return $withPostsOnly
            ? ''
            : ' AND NOT EXISTS (SELECT 1 FROM {{%publications_post_map}} fpm WHERE fpm.post_id = bp.id)';
    }

    /**
     * The image filters of the header. They run over the posts of a topic
     * before those posts are numbered, so the window a page reads from and
     * the count of the discussion speak about the same set of rows. The alias
     * names the post row being read: the list of posts numbers 'bp', the rule
     * that keeps a viewed topic on the page reads 'fp', and both get the same
     * conditions from here.
     */
    private function postImageFilterSql(bool $withImagesOnly, int $imagesCount, string $alias): string
    {
        return ($withImagesOnly ? ' AND ' . $alias . '.image_urls != \'[]\'::jsonb' : '')
            . ($imagesCount > 0 ? ' AND jsonb_array_length(' . $alias . '.image_urls) = ' . $imagesCount : '');
    }

    /**
     * The link filters of the header. They count the addresses of a post's own
     * text, the same links_count the topic rule of topicFilterSql() reads, so
     * a discussion that reached the page is made of posts that answer to it.
     */
    private function postLinkFilterSql(bool $withLinksOnly, int $linksCount, string $alias): string
    {
        return ($withLinksOnly ? ' AND ' . $alias . '.links_count > 0' : '')
            . ($linksCount > 0 ? ' AND ' . $alias . '.links_count = ' . $linksCount : '');
    }

    /**
     * Marks a forum topic as viewed by the "Просмотрено" button:
     * inserts a publications_topic_map row with an empty telegram_id.
     * An existing map row is left untouched — a topic already
     * published to the channel keeps its telegram_id. The post that
     * repeats the topic, the one numbered 1 of its thread, is marked
     * viewed as well.
     */
    public function markTopicViewed(int $topicId): void
    {
        $this->insertMapRowIfMissing('{{%publications_topic_map}}', 'topic_id', $topicId);
        $this->mirrorFirstPost($topicId, null);
    }

    /**
     * Marks a forum post as viewed by the "Просмотрено" button:
     * inserts a publications_post_map row with an empty telegram_id.
     * An existing map row is left untouched — a post already
     * published to the channel keeps its telegram_id. When the post is
     * the one that repeats its topic, the topic is marked viewed as well.
     */
    public function markPostViewed(int $postId): void
    {
        $this->insertMapRowIfMissing('{{%publications_post_map}}', 'post_id', $postId);
        $this->mirrorTopicOfFirstPost($postId, null);
    }

    /**
     * Writes the publications_topic_map row of a topic when a
     * publication created from the topic is saved (empty telegram_id)
     * or reaches the Telegram channel (stamps the telegram_id of the
     * channel message). The post that repeats the topic receives the
     * same state.
     */
    public function storeTopicMapTelegramId(int $topicId, ?int $telegramId): void
    {
        $this->storeMapTelegramId('{{%publications_topic_map}}', 'topic_id', $topicId, $telegramId);
        $this->mirrorFirstPost($topicId, $telegramId);
    }

    /**
     * Writes the publications_post_map row of a forum post when a
     * publication created from the post is saved (empty telegram_id)
     * or reaches the Telegram channel (stamps the telegram_id of the
     * channel message). When the post is the one that repeats its
     * topic, the topic receives the same state.
     */
    public function storePostMapTelegramId(int $postId, ?int $telegramId): void
    {
        $this->storeMapTelegramId('{{%publications_post_map}}', 'post_id', $postId, $telegramId);
        $this->mirrorTopicOfFirstPost($postId, $telegramId);
    }

    /**
     * Inserts a map row with an empty telegram_id unless it already
     * exists; an existing telegram_id is never overwritten.
     */
    private function insertMapRowIfMissing(string $table, string $column, int $entityId): void
    {
        $exists = $this->db
            ->createCommand("SELECT 1 FROM {$table} WHERE {$column} = :id")
            ->bindValue(':id', $entityId)
            ->queryScalar() !== false;

        if (!$exists) {
            $this->db
                ->createCommand()
                ->insert($table, [$column => $entityId, 'telegram_id' => null])
                ->execute();
        }
    }

    /**
     * A null telegram_id only inserts a missing row (an existing
     * telegram_id is preserved); a non-null value upserts the row and
     * stamps the telegram_id of the channel message.
     */
    private function storeMapTelegramId(string $table, string $column, int $entityId, ?int $telegramId): void
    {
        $exists = $this->db
            ->createCommand("SELECT 1 FROM {$table} WHERE {$column} = :id")
            ->bindValue(':id', $entityId)
            ->queryScalar() !== false;

        if (!$exists) {
            $this->db
                ->createCommand()
                ->insert($table, [$column => $entityId, 'telegram_id' => $telegramId])
                ->execute();

            return;
        }

        if ($telegramId !== null) {
            $this->db
                ->createCommand()
                ->update($table, ['telegram_id' => $telegramId], [$column => $entityId])
                ->execute();
        }
    }

    /**
     * Gives the post that repeats the topic the state of the topic: a topic
     * and the post numbered 1 of its thread are one and the same element,
     * because that is the body of the topic the parser wrote a second time.
     * How long the discussion is does not matter — its replies are their own
     * business. A thread the parser never opened at post 1 has no twin.
     */
    private function mirrorFirstPost(int $topicId, ?int $telegramId): void
    {
        $postId = $this->db
            ->createCommand('SELECT id FROM {{%post}} WHERE topic_id = :topic_id AND number = 1')
            ->bindValue(':topic_id', $topicId)
            ->queryScalar();

        if ($postId !== false && $postId !== null) {
            $this->mirrorMapRow('{{%publications_post_map}}', 'post_id', (int)$postId, $telegramId);
        }
    }

    /**
     * Gives a topic the state of the post that repeats it, which is the
     * reverse direction of the rule above. A reply says nothing about its
     * topic, however many replies the thread holds.
     */
    private function mirrorTopicOfFirstPost(int $postId, ?int $telegramId): void
    {
        $topicId = $this->db
            ->createCommand('SELECT topic_id FROM {{%post}} WHERE id = :post_id AND number = 1')
            ->bindValue(':post_id', $postId)
            ->queryScalar();

        if ($topicId !== false && $topicId !== null) {
            $this->mirrorMapRow('{{%publications_topic_map}}', 'topic_id', (int)$topicId, $telegramId);
        }
    }

    /**
     * Same as storeMapTelegramId but never overwrites a telegram_id the
     * mirrored row already has: the twin entity may have been published
     * as a message of its own earlier, and that link stays authoritative.
     */
    private function mirrorMapRow(string $table, string $column, int $entityId, ?int $telegramId): void
    {
        $exists = $this->db
            ->createCommand("SELECT 1 FROM {$table} WHERE {$column} = :id")
            ->bindValue(':id', $entityId)
            ->queryScalar() !== false;

        if (!$exists) {
            $this->db
                ->createCommand()
                ->insert($table, [$column => $entityId, 'telegram_id' => $telegramId])
                ->execute();

            return;
        }

        if ($telegramId !== null) {
            $this->db
                ->createCommand("UPDATE {$table} SET telegram_id = :telegram_id WHERE {$column} = :id AND telegram_id IS NULL")
                ->bindValue(':telegram_id', $telegramId)
                ->bindValue(':id', $entityId)
                ->execute();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTopicRow(array $row): TopicData
    {
        return new TopicData(
            (int)$row['id'],
            (string)$row['source_url'],
            (string)$row['title'],
            $row['published_at'] === null ? null : (string)$row['published_at'],
            (string)$row['content_html'],
            (string)$row['content_text'],
            self::decodeImageUrls($row['image_urls']),
            $this->hydrateAuthorRow($row),
            false,
            self::publicationStatus($row['publication_map_id'] ?? null, $row['publication_telegram_id'] ?? null),
            self::publicationTelegramId($row['publication_map_id'] ?? null, $row['publication_telegram_id'] ?? null),
        );
    }

    /**
     * A post row of either of the two paged queries: the author is hydrated
     * from the same joined member columns the topic list carries.
     *
     * @param array<string, mixed> $row
     */
    private function hydratePostRow(array $row): PostData
    {
        return new PostData(
            (int)$row['id'],
            (int)$row['topic_id'],
            $row['author_id'] === null ? null : (int)$row['author_id'],
            $row['number'] === null ? null : (int)$row['number'],
            (string)$row['title'],
            $row['posted_at'] === null ? null : (string)$row['posted_at'],
            (string)$row['content_html'],
            (string)$row['content_text'],
            (string)$row['source_url'],
            $this->hydrateAuthorRow($row),
            self::decodeImageUrls($row['image_urls']),
            self::publicationStatus($row['publication_map_id'], $row['publication_telegram_id']),
            self::publicationTelegramId($row['publication_map_id'], $row['publication_telegram_id']),
        );
    }

    /**
     * Map rows: no map record = not seen (null), record with empty
     * telegram_id = viewed, record with telegram_id = published.
     */
    private static function publicationStatus(mixed $mapId, mixed $telegramId): ?string
    {
        if ($mapId === null) {
            return null;
        }

        $value = (string)$telegramId;

        return $value === '' || $value === '0' ? 'viewed' : 'published';
    }

    /**
     * The telegram message id of a published record, null otherwise.
     */
    private static function publicationTelegramId(mixed $mapId, mixed $telegramId): ?string
    {
        if ($mapId === null || $telegramId === null) {
            return null;
        }

        $value = (string)$telegramId;

        return $value === '' || $value === '0' ? null : $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAuthorRow(array $row): ?MemberData
    {
        if ($row['author_id'] === null) {
            return null;
        }

        return new MemberData(
            (int)$row['author_id'],
            (string)($row['author_profile_url'] ?? ''),
            (string)($row['author_name'] ?? ''),
            $row['author_avatar_url'] === null ? null : (string)$row['author_avatar_url'],
            $row['author_rank_name'] === null ? null : (string)$row['author_rank_name'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            [],
        );
    }

    /**
     * jsonb columns arrive as JSON strings; arrays pass through.
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
