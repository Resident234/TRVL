<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Forum\Infrastructure;

use app\shared\Forum\Infrastructure\ForumRepository;
use Codeception\Test\Unit;
use PDO;
use Yii;
use yii\db\Connection;

/**
 * A topic and the post that repeats it are one and the same element for the
 * channel: the parser writes the body of a topic a second time as the post
 * numbered 1 of its thread. Whatever state one of them takes, the other takes
 * with it — a thread of nine posts mirrors its first one, and a reply never
 * mirrors its topic.
 */
final class ForumFirstPostMirrorTest extends Unit
{
    private const TOPIC_FROM = 900000001;
    private const TOPIC_TO = 900000099;

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

    public function testViewedTopicMarksItsOwnFirstPostInAThreadOfThree(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000001, 900000001, 1);
        $this->insertPost(900000002, 900000001, 2);
        $this->insertPost(900000003, 900000001, 3);

        $this->_repository->markTopicViewed(900000001);

        $this->assertSame(['marked' => true, 'telegram_id' => null], $this->postMap(900000001));
        $this->assertSame([], $this->postMap(900000002));
        $this->assertSame([], $this->postMap(900000003));
    }

    public function testPublishedTopicStampsItsFirstPostWithTheSameTelegramId(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000001, 900000001, 1);
        $this->insertPost(900000002, 900000001, 2);

        $this->_repository->storeTopicMapTelegramId(900000001, 7001);

        $this->assertSame(['marked' => true, 'telegram_id' => 7001], $this->postMap(900000001));
        $this->assertSame(['marked' => true, 'telegram_id' => 7001], $this->topicMap(900000001));
        $this->assertSame([], $this->postMap(900000002));
    }

    public function testViewedFirstPostMarksItsTopic(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000001, 900000001, 1);
        $this->insertPost(900000002, 900000001, 2);

        $this->_repository->markPostViewed(900000001);

        $this->assertSame(['marked' => true, 'telegram_id' => null], $this->topicMap(900000001));
    }

    public function testViewedReplyLeavesItsTopicAndItsTwinAlone(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000001, 900000001, 1);
        $this->insertPost(900000002, 900000001, 2);
        $this->insertPost(900000003, 900000001, 3);

        $this->_repository->markPostViewed(900000002);

        $this->assertSame([], $this->topicMap(900000001));
        $this->assertSame([], $this->postMap(900000001));
    }

    public function testSoleReplyPostDoesNotMarkItsTopic(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000002, 900000001, 2);

        $this->_repository->markPostViewed(900000002);

        $this->assertSame([], $this->topicMap(900000001));
    }

    public function testTopicWithoutItsFirstPostMarksNoPost(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000002, 900000001, 2);
        $this->insertPost(900000003, 900000001, 3);

        $this->_repository->markTopicViewed(900000001);

        $this->assertSame([], $this->postMap(900000002));
        $this->assertSame([], $this->postMap(900000003));
    }

    public function testFirstPostAlreadyPublishedKeepsItsOwnTelegramId(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000001, 900000001, 1);
        $this->_db
            ->createCommand()
            ->insert('{{%publications_post_map}}', ['post_id' => 900000001, 'telegram_id' => 7002])
            ->execute();

        $this->_repository->markTopicViewed(900000001);

        $this->assertSame(['marked' => true, 'telegram_id' => 7002], $this->postMap(900000001));
    }

    public function testFirstPostOfAThreadOfOneStillMirrors(): void
    {
        $this->insertTopic(900000001);
        $this->insertPost(900000001, 900000001, 1);

        $this->_repository->markTopicViewed(900000001);

        $this->assertSame(['marked' => true, 'telegram_id' => null], $this->postMap(900000001));
    }

    /**
     * The row of a topic in the map table: `['marked' => true, …]` once the
     * element is processed, an empty array while it is not. PostgreSQL hands
     * the number back as a string, the assertions read it as an int.
     *
     * @return array<string, mixed>
     */
    private function topicMap(int $topicId): array
    {
        return $this->mapRow('{{%publications_topic_map}}', 'topic_id', $topicId);
    }

    /**
     * @return array<string, mixed>
     */
    private function postMap(int $postId): array
    {
        return $this->mapRow('{{%publications_post_map}}', 'post_id', $postId);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(string $table, string $column, int $entityId): array
    {
        $row = $this->_db
            ->createCommand("SELECT telegram_id FROM {$table} WHERE {$column} = :id")
            ->bindValue(':id', $entityId)
            ->queryOne(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [];
        }

        return ['marked' => true, 'telegram_id' => $row['telegram_id'] === null ? null : (int)$row['telegram_id']];
    }

    private function insertTopic(int $id): void
    {
        $this->_db
            ->createCommand()
            ->insert('{{%topic}}', $this->auditColumns() + [
                'id' => $id,
                'source_url' => 'https://forum.awd.ru/viewtopic.php?t=' . $id,
                'title' => 'Топик-образец',
                'published_at' => '2026-10-06 10:00:00',
                'content_html' => '<p>Текст топика</p>',
                'content_text' => 'Текст топика',
            ])
            ->execute();
    }

    private function insertPost(int $id, int $topicId, int $number): void
    {
        $this->_db
            ->createCommand()
            ->insert('{{%post}}', $this->auditColumns() + [
                'id' => $id,
                'topic_id' => $topicId,
                'number' => $number,
                'title' => 'Топик-образец',
                'posted_at' => '2026-10-06 10:00:00',
                'content_html' => '<p>Текст топика</p>',
                'content_text' => 'Текст топика',
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
        // Both map tables hang off topic and post with ON DELETE CASCADE, so
        // the topic row is the only one that has to go.
        $this->_db
            ->createCommand('DELETE FROM {{%topic}} WHERE id BETWEEN :from AND :to')
            ->bindValues([':from' => self::TOPIC_FROM, ':to' => self::TOPIC_TO])
            ->execute();
    }
}
