<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Forum\Infrastructure;

use app\shared\Forum\Infrastructure\ForumRepository;
use Codeception\Test\Unit;
use Yii;
use yii\db\Connection;

/**
 * The filter compares the message the publish button builds — the title,
 * the blank line and the text — with the sender's bound. A topic is kept by
 * its own message, never by a short reply; the posts of every shown
 * discussion and the page of one discussion use the same rule, so the count,
 * the list and the scroller speak about the same set of rows.
 */
final class ForumRepositoryTextLimitFilterTest extends Unit
{
    private const TOPIC_FROM = 900200001;
    private const TOPIC_TO = 900200099;
    private const TEXT_LIMIT = 4096;

    private Connection $_db;
    private ForumRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        $this->_db = Yii::$app->getDb();
        $this->_repository = new ForumRepository($this->_db);
        $this->wipeFixtures();
    }

    protected function _after(): void
    {
        $this->wipeFixtures();

        parent::_after();
    }

    public function testCountAsksAboutTheMessageOfTheTopicItself(): void
    {
        // Its own message fits; one of its replies does not.
        $this->insertTopic(900200001, 10);
        $this->insertPost(900200001, 900200001, 1, 10);
        $this->insertPost(900200002, 900200001, 2, 5000);

        // Its own message is too long even though a reply fits.
        $this->insertTopic(900200002, 5000);
        $this->insertPost(900200003, 900200002, 1, 10);
        $this->insertPost(900200004, 900200002, 2, 5000);

        // Neither the topic nor its reply fits.
        $this->insertTopic(900200003, 5000);
        $this->insertPost(900200005, 900200003, 1, 5000);

        // Its own message fits; the single reply does not.
        $this->insertTopic(900200004, 10);
        $this->insertPost(900200006, 900200004, 1, 5000);

        $this->assertSame(4, $this->_repository->countTopics());
        $this->assertSame(2, $this->_repository->countTopics(maxTextLength: self::TEXT_LIMIT));
    }

    public function testTheTopicListCutsItsOwnMessageAndThePostsUnderIt(): void
    {
        $this->insertTopic(900200001, 10);
        $this->insertPost(900200001, 900200001, 1, 10);
        $this->insertPost(900200002, 900200001, 2, 5000);

        // A short post may not keep an overlong topic on the page.
        $this->insertTopic(900200002, 5000);
        $this->insertPost(900200003, 900200002, 1, 10);

        // The topic fits, so it stays even with no fitting post under it.
        $this->insertTopic(900200004, 10);
        $this->insertPost(900200006, 900200004, 1, 5000);

        $rows = $this->_repository->latestTopicsWithPosts(10, 10, maxTextLength: self::TEXT_LIMIT);
        $topics = [];
        foreach ($rows as $row) {
            $topics[$row['topic']->id] = [
                'posts' => array_map(static fn ($post): int => $post->id, $row['posts']),
                'total' => $row['postsTotal'],
            ];
        }
        ksort($topics);

        $this->assertSame([900200001, 900200004], array_keys($topics));
        $this->assertSame(['posts' => [900200001], 'total' => 1], $topics[900200001]);
        $this->assertSame(['posts' => [], 'total' => 0], $topics[900200004]);
    }

    public function testTheReplyPageUsesTheSameBoundIncludingItsTotal(): void
    {
        $this->insertTopic(900200001, 10);
        $this->insertPost(900200001, 900200001, 1, 10);
        $this->insertPost(900200002, 900200001, 2, 5000);

        $page = $this->_repository->topicPosts(900200001, 10, 0, maxTextLength: self::TEXT_LIMIT);
        $this->assertSame([900200001], array_map(static fn ($post): int => $post->id, $page['posts']));
        $this->assertSame(1, $page['total']);

        $all = $this->_repository->topicPosts(900200001, 10, 0);
        $this->assertSame([900200002, 900200001], array_map(static fn ($post): int => $post->id, $all['posts']));
        $this->assertSame(2, $all['total']);
    }

    public function testTheTitleAndItsBlankLineAreCountedForATopic(): void
    {
        // A title is a varchar(1000) here: 3094 + 1000 + 2 = exactly the bound.
        $this->insertTopic(900200001, 3094, str_repeat('а', 1000));
        // One more character of the body tips it over.
        $this->insertTopic(900200002, 3095, str_repeat('а', 1000));
        // A title with no body needs no blank line and always fits.
        $this->insertTopic(900200003, 0, str_repeat('а', 1000));
        // A body of the bound without a title fits as well.
        $this->insertTopic(900200004, self::TEXT_LIMIT);

        $this->assertSame(3, $this->_repository->countTopics(maxTextLength: self::TEXT_LIMIT));

        $ids = array_map(
            static fn (array $row): int => $row['topic']->id,
            $this->_repository->latestTopicsWithPosts(10, 10, maxTextLength: self::TEXT_LIMIT),
        );
        sort($ids);
        $this->assertSame([900200001, 900200003, 900200004], $ids);
    }

    public function testTheTitleAndItsBlankLineAreCountedForAPost(): void
    {
        $this->insertTopic(900200001, 10);

        $this->insertPost(900200001, 900200001, 1, 3094, str_repeat('а', 1000));
        $this->insertPost(900200002, 900200001, 2, 3095, str_repeat('а', 1000));
        $this->insertPost(900200003, 900200001, 3, 0, str_repeat('а', 1000));
        $this->insertPost(900200004, 900200001, 4, self::TEXT_LIMIT);

        $page = $this->_repository->topicPosts(900200001, 10, 0, maxTextLength: self::TEXT_LIMIT);
        $ids = array_map(static fn ($post): int => $post->id, $page['posts']);
        sort($ids);
        $this->assertSame([900200001, 900200003, 900200004], $ids);
        $this->assertSame(3, $page['total']);
    }

    public function testTheBodyOfExactlyTheLimitFitsWithoutATitle(): void
    {
        $this->insertTopic(900200001, self::TEXT_LIMIT);
        $this->insertPost(900200001, 900200001, 1, self::TEXT_LIMIT + 1);

        $this->assertSame(1, $this->_repository->countTopics(maxTextLength: self::TEXT_LIMIT));

        $rows = $this->_repository->latestTopicsWithPosts(10, 10, maxTextLength: self::TEXT_LIMIT);
        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['posts']);
    }

    private function insertTopic(int $id, int $textLength, string $title = ''): void
    {
        $this->_db
            ->createCommand()
            ->insert('{{%topic}}', $this->auditColumns() + [
                'id' => $id,
                'source_url' => 'https://forum.awd.ru/viewtopic.php?t=' . $id,
                'title' => $title,
                'published_at' => '2026-10-06 10:00:00',
                'content_html' => '<p>Текст топика</p>',
                'content_text' => str_repeat('а', $textLength),
                'text_length' => $textLength,
            ])
            ->execute();
    }

    private function insertPost(int $id, int $topicId, int $number, int $textLength, string $title = ''): void
    {
        $this->_db
            ->createCommand()
            ->insert('{{%post}}', $this->auditColumns() + [
                'id' => $id,
                'topic_id' => $topicId,
                'number' => $number,
                'title' => $title,
                'posted_at' => '2026-10-06 10:00:00',
                'content_html' => '<p>Текст поста</p>',
                'content_text' => str_repeat('а', $textLength),
                'source_url' => 'https://forum.awd.ru/viewtopic.php?p=' . $id,
            ])
            ->execute();
    }

    /**
     * @return array<string, string>
     */
    private function auditColumns(): array
    {
        return ['created_at' => '2026-10-06 10:00:00', 'updated_at' => '2026-10-06 10:00:00'];
    }

    private function wipeFixtures(): void
    {
        // Posts and both map tables hang off the topic with ON DELETE CASCADE.
        $this->_db
            ->createCommand('DELETE FROM {{%topic}} WHERE id BETWEEN :from AND :to')
            ->bindValues([':from' => self::TOPIC_FROM, ':to' => self::TOPIC_TO])
            ->execute();
    }
}
