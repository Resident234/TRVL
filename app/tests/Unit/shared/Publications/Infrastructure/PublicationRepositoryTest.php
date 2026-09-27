<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Infrastructure;

use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Infrastructure\PublicationRepository;
use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;
use InvalidArgumentException;
use Yii;
use yii\db\Exception as DbException;

final class PublicationRepositoryTest extends Unit
{
    private PublicationRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%publications_draft}} RESTART IDENTITY')
            ->execute();
        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%publications_post}} RESTART IDENTITY')
            ->execute();
        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%publications_deleted}} RESTART IDENTITY')
            ->execute();
        $this->_repository = new PublicationRepository(Yii::$app->getDb());
    }

    public function testCreateDraftSavesRowWithImageUrls(): void
    {
        $this->_repository->createDraft(
            'Текст черновика',
            ['https://example.com/a.png'],
            '2026-09-10 10:00:00',
            new MessageEntities(),
            new LinkButton(),
        );

        $drafts = $this->_repository->allDrafts();

        $this->assertCount(1, $drafts);
        $this->assertSame('Текст черновика', $drafts[0]->text);
        $this->assertSame(['https://example.com/a.png'], $drafts[0]->imageUrls);
        $this->assertNull($drafts[0]->telegramId);
        $this->assertNull($drafts[0]->publishedAt);
        $this->assertSame('2026-09-10 10:00:00', $drafts[0]->createdAt);
    }

    public function testCreatePostSavesScheduledTime(): void
    {
        $this->_repository->createPost(
            'Текст поста',
            [],
            '2026-09-10 21:30:00',
            '2026-09-10 10:00:00',
            new MessageEntities(),
            new LinkButton(),
        );

        $posts = $this->_repository->allPosts();

        $this->assertCount(1, $posts);
        $this->assertSame('Текст поста', $posts[0]->text);
        $this->assertSame('2026-09-10 21:30:00', $posts[0]->publishedAt);
        $this->assertNull($posts[0]->telegramId);
    }

    public function testFormattingSurvivesCreateAndUpdate(): void
    {
        $bold = [['type' => 'bold', 'offset' => 0, 'length' => 5]];
        $italic = [['type' => 'italic', 'offset' => 7, 'length' => 5]];

        $this->_repository->createDraft('Жирный текст', [], '2026-09-10 10:00:00', new MessageEntities($bold), new LinkButton());
        $this->_repository->createPost('Жирный текст', [], '2026-09-10 21:30:00', '2026-09-10 10:00:00', new MessageEntities($bold), new LinkButton());

        $this->assertSame($bold, $this->_repository->allDrafts()[0]->formatting->toArray());
        $this->assertSame($bold, $this->_repository->allPosts()[0]->formatting->toArray());

        $this->_repository->updateDraft(1, 'Курсивный текст', [], '2026-09-10 11:00:00', new MessageEntities($italic), new LinkButton());
        $this->_repository->updatePost(1, 'Курсивный текст', [], '2026-09-10 22:00:00', '2026-09-10 11:00:00', new MessageEntities($italic), new LinkButton());

        $this->assertSame($italic, $this->_repository->allDrafts()[0]->formatting->toArray());
        $this->assertSame($italic, $this->_repository->allPosts()[0]->formatting->toArray());
    }

    public function testButtonSurvivesCreateAndUpdate(): void
    {
        $poll = new LinkButton('Пройти опрос', 'https://example.com/poll');

        $this->_repository->createDraft('Черновик с кнопкой', [], '2026-09-10 10:00:00', new MessageEntities(), $poll);
        $this->_repository->createPost('Пост с кнопкой', [], '2026-09-10 21:30:00', '2026-09-10 10:00:00', new MessageEntities(), $poll);

        $this->assertSame($poll->toArray(), $this->_repository->allDrafts()[0]->button->toArray());
        $this->assertSame($poll->toArray(), $this->_repository->allPosts()[0]->button->toArray());

        $this->_repository->updatePost(
            1,
            'Пост без кнопки',
            [],
            '2026-09-10 22:00:00',
            '2026-09-10 11:00:00',
            new MessageEntities(),
            new LinkButton(),
        );

        $post = $this->_repository->allPosts()[0];
        $this->assertTrue($post->button->isEmpty());
        $this->assertSame('', $post->button->text);
        $this->assertSame('', $post->button->url);
    }

    public function testCreatePostsStoresEveryPartInOrder(): void
    {
        $firstId = $this->_repository->createPosts([
            [
                'text' => 'Часть 1',
                'imageUrls' => ['https://example.com/a.png'],
                'formatting' => new MessageEntities(),
                'button' => new LinkButton('Пройти опрос', 'https://example.com/poll'),
                'publishedAt' => '2026-09-10 21:30:00',
            ],
            [
                'text' => 'Часть 2',
                'imageUrls' => [],
                'formatting' => new MessageEntities(),
                'button' => new LinkButton(),
                'publishedAt' => '2026-09-10 21:31:00',
            ],
            [
                'text' => 'Часть 3',
                'imageUrls' => [],
                'formatting' => new MessageEntities(),
                'button' => new LinkButton(),
                'publishedAt' => '2026-09-10 21:32:00',
            ],
        ], '2026-09-10 10:00:00');

        $due = $this->_repository->findDueForPublishing('2026-09-10 21:35:00');

        $this->assertGreaterThan(0, $firstId);
        $this->assertCount(3, $due);
        $this->assertSame($firstId, $due[0]->id);
        $this->assertSame(
            ['Часть 1', 'Часть 2', 'Часть 3'],
            array_map(static fn (PublicationData $post): string => $post->text, $due),
        );
        $this->assertSame('2026-09-10 21:31:00', $due[1]->publishedAt);
        $this->assertSame(['https://example.com/a.png'], $due[0]->imageUrls);
        $this->assertSame([], $due[2]->imageUrls);
        $this->assertSame(
            ['Пройти опрос', '', ''],
            array_map(static fn (PublicationData $post): string => $post->button->text, $due),
            'Every part must come back with the button of its own row.',
        );
    }

    public function testCreateDraftsStoresEveryPart(): void
    {
        $firstId = $this->_repository->createDrafts([
            ['text' => 'Часть 1', 'imageUrls' => ['https://example.com/a.png'], 'formatting' => new MessageEntities(), 'button' => new LinkButton()],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'button' => new LinkButton()],
        ], '2026-09-10 10:00:00');

        $drafts = $this->_repository->allDrafts();

        $this->assertGreaterThan(0, $firstId);
        $this->assertCount(2, $drafts);
        $this->assertContains('Часть 1', array_column($drafts, 'text'));
        $this->assertContains('Часть 2', array_column($drafts, 'text'));
        $this->assertNull($drafts[0]->publishedAt);
    }

    public function testCreatePostsLeavesNoRowWhenAPartFails(): void
    {
        try {
            $this->_repository->createPosts([
                [
                    'text' => 'Часть 1',
                    'imageUrls' => [],
                    'formatting' => new MessageEntities(),
                    'button' => new LinkButton(),
                    'publishedAt' => '2026-09-10 21:30:00',
                ],
                [
                    'text' => 'Часть 2',
                    'imageUrls' => [],
                    'formatting' => new MessageEntities(),
                    'button' => new LinkButton(),
                    'publishedAt' => 'не дата',
                ],
            ], '2026-09-10 10:00:00');
            $this->fail('Вторая часть с неразбираемой датой должна была сорвать вставку.');
        } catch (DbException $e) {
            $this->assertStringContainsString('22007', $e->getMessage());
        }

        $this->assertCount(0, $this->_repository->allPosts());
    }

    public function testAllPostsSortedByPublishedAtDescending(): void
    {
        $this->_repository->createPost('Поздний', [], '2026-09-10 22:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButton());
        $this->_repository->createPost('Ранний', [], '2026-09-10 21:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButton());

        $posts = $this->_repository->allPosts();

        $this->assertSame('Поздний', $posts[0]->text);
        $this->assertSame('Ранний', $posts[1]->text);
    }

    public function testAllDraftsSortedByUpdatedAtDescending(): void
    {
        $this->_repository->createDraft('Первый', [], '2026-09-10 10:00:00', new MessageEntities(), new LinkButton());
        $this->_repository->createDraft('Второй', [], '2026-09-10 11:00:00', new MessageEntities(), new LinkButton());
        $this->_repository->updateDraft(1, 'Первый обновлён', [], '2026-09-10 12:00:00', new MessageEntities(), new LinkButton());

        $drafts = $this->_repository->allDrafts();

        $this->assertSame('Первый обновлён', $drafts[0]->text);
        $this->assertSame('Второй', $drafts[1]->text);
    }

    public function testFindDueForPublishingReturnsOnlyDueUnpublished(): void
    {
        $this->_repository->createPost('Прошлое', [], '2026-09-09 12:00:00', '2026-09-09 10:00:00', new MessageEntities(), new LinkButton());
        $this->_repository->createPost('Наступившее', [], '2026-09-10 12:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButton());
        $this->_repository->createPost('Будущее', [], '2026-09-11 12:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButton());

        $due = $this->_repository->findDueForPublishing('2026-09-10 20:00:00');

        $this->assertCount(2, $due);
        $this->assertSame('Прошлое', $due[0]->text);
        $this->assertSame('Наступившее', $due[1]->text);
    }

    public function testFindDueSkipsPostsWithTelegramId(): void
    {
        $this->_repository->createPost('Опубликованный', [], '2026-09-09 12:00:00', '2026-09-09 10:00:00', new MessageEntities(), new LinkButton());
        $this->_repository->storeTelegramId(1, 4242, '2026-09-09 12:05:00', '2026-09-09 12:05:30');

        $due = $this->_repository->findDueForPublishing('2026-09-10 20:00:00');

        $this->assertSame([], $due);
        $posts = $this->_repository->allPosts();
        $this->assertSame(4242, $posts[0]->telegramId);
        $this->assertSame(
            '2026-09-09 12:05:00',
            $posts[0]->publishedAt,
            'published_at must be corrected to the actual send time.',
        );
        $this->assertSame('2026-09-09 12:05:30', $posts[0]->updatedAt);
    }

    public function testAllDeletedSortedByUpdatedAtDescending(): void
    {
        $post = new PublicationData(1, 'Пост', [], null, '2026-09-10 21:00:00', '2026-09-10 10:00:00', '2026-09-10 10:00:00');
        $draft = new PublicationData(2, 'Черновик', [], null, null, '2026-09-10 09:00:00', '2026-09-10 09:00:00');
        $this->_repository->insertDeletedWithHistory($post, '2026-09-10 12:00:00');
        $this->_repository->insertDeletedWithHistory($draft, '2026-09-10 13:00:00');

        $deleted = $this->_repository->allDeleted();

        $this->assertCount(2, $deleted);
        $this->assertSame('Черновик', $deleted[0]->text);
        $this->assertNull($deleted[0]->publishedAt);
        $this->assertNull($deleted[0]->deletedAt);
        $this->assertSame('2026-09-10 13:00:00', $deleted[0]->updatedAt);
        $this->assertSame('Пост', $deleted[1]->text);
        $this->assertSame('2026-09-10 21:00:00', $deleted[1]->publishedAt);
    }

    public function testDeleteDeletedReturnsRecordAndRemovesRow(): void
    {
        $post = new PublicationData(1, 'Пост', [], 4242, '2026-09-10 21:00:00', '2026-09-10 10:00:00', '2026-09-10 10:00:00');
        $this->_repository->insertDeletedWithHistory($post, '2026-09-10 12:00:00');

        $record = $this->_repository->deleteDeleted(1);

        $this->assertSame('Пост', $record->text);
        $this->assertSame(4242, $record->telegramId);
        $this->assertSame('2026-09-10 10:00:00', $record->createdAt);
        $this->assertSame([], $this->_repository->allDeleted());
    }

    public function testDeleteDeletedThrowsWhenRecordNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->_repository->deleteDeleted(999);
    }

    public function testRestoredDeletedRecordMovesToPosts(): void
    {
        $post = new PublicationData(
            1,
            'Восстанавливаемый',
            [],
            4242,
            '2026-09-10 21:00:00',
            '2026-09-10 10:00:00',
            '2026-09-10 10:00:00',
            formatting: new MessageEntities([['type' => 'bold', 'offset' => 0, 'length' => 5]]),
            button: new LinkButton('Открыть', 'https://example.com/open'),
        );
        $this->_repository->insertDeletedWithHistory($post, '2026-09-10 12:00:00');

        $record = $this->_repository->deleteDeleted(1);
        $this->_repository->insertPostWithHistory(
            new PublicationData(
                $record->id,
                $record->text,
                $record->imageUrls,
                null,
                '2026-09-11 12:00:00',
                $record->createdAt,
                $record->updatedAt,
                formatting: $record->formatting,
                button: $record->button,
            ),
            '2026-09-11 12:00:00',
        );

        $posts = $this->_repository->allPosts();
        $this->assertCount(1, $posts);
        $this->assertSame('Восстанавливаемый', $posts[0]->text);
        $this->assertNull($posts[0]->telegramId);
        $this->assertSame('2026-09-11 12:00:00', $posts[0]->publishedAt);
        $this->assertSame('2026-09-10 10:00:00', $posts[0]->createdAt);
        $this->assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 5]],
            $posts[0]->formatting->toArray(),
            'A restored record must carry its formatting back into the posts table.',
        );
        $this->assertSame(
            ['text' => 'Открыть', 'url' => 'https://example.com/open'],
            $posts[0]->button->toArray(),
            'A restored record must carry its button back into the posts table.',
        );
    }

    public function testRestoredDeletedRecordMovesToDrafts(): void
    {
        $draft = new PublicationData(1, 'Восстанавливаемый черновик', [], null, null, '2026-09-10 09:00:00', '2026-09-10 09:00:00');
        $this->_repository->insertDeletedWithHistory($draft, '2026-09-10 12:00:00');

        $record = $this->_repository->deleteDeleted(1);
        $this->_repository->insertDraftWithHistory($record, '2026-09-11 12:00:00');

        $drafts = $this->_repository->allDrafts();
        $this->assertCount(1, $drafts);
        $this->assertSame('Восстанавливаемый черновик', $drafts[0]->text);
        $this->assertNull($drafts[0]->publishedAt);
        $this->assertSame('2026-09-10 09:00:00', $drafts[0]->createdAt);
    }
}
