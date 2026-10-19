<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Infrastructure;

use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Infrastructure\PublicationRepository;
use app\shared\Telegram\Dto\LinkButtons;
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
        Yii::$app->getDb()
            ->createCommand('TRUNCATE TABLE {{%publications_edited}} RESTART IDENTITY')
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
            new LinkButtons(),
            '',
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
            new LinkButtons(),
            '',
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

        $this->_repository->createDraft('Жирный текст', [], '2026-09-10 10:00:00', new MessageEntities($bold), new LinkButtons(), '');
        $this->_repository->createPost('Жирный текст', [], '2026-09-10 21:30:00', '2026-09-10 10:00:00', new MessageEntities($bold), new LinkButtons(), '');

        $this->assertSame($bold, $this->_repository->allDrafts()[0]->formatting->toArray());
        $this->assertSame($bold, $this->_repository->allPosts()[0]->formatting->toArray());

        $this->_repository->updateDraft(1, 'Курсивный текст', [], '2026-09-10 11:00:00', new MessageEntities($italic), new LinkButtons(), '');
        $this->_repository->updatePost(1, 'Курсивный текст', [], '2026-09-10 22:00:00', '2026-09-10 11:00:00', new MessageEntities($italic), new LinkButtons(), '');

        $this->assertSame($italic, $this->_repository->allDrafts()[0]->formatting->toArray());
        $this->assertSame($italic, $this->_repository->allPosts()[0]->formatting->toArray());
    }

    public function testButtonsSurviveCreateAndUpdate(): void
    {
        $poll = LinkButtons::fromPairs(
            ['Пройти опрос', 'Открыть'],
            ['https://example.com/poll', 'https://example.com/open'],
        );

        $this->_repository->createDraft('Черновик с кнопками', [], '2026-09-10 10:00:00', new MessageEntities(), $poll, '');
        $this->_repository->createPost('Пост с кнопками', [], '2026-09-10 21:30:00', '2026-09-10 10:00:00', new MessageEntities(), $poll, '');

        $this->assertSame($poll->toArray(), $this->_repository->allDrafts()[0]->buttons->toArray());
        $this->assertSame($poll->toArray(), $this->_repository->allPosts()[0]->buttons->toArray());

        $this->_repository->updatePost(
            1,
            'Пост без кнопок',
            [],
            '2026-09-10 22:00:00',
            '2026-09-10 11:00:00',
            new MessageEntities(),
            LinkButtons::empty(),
            '',
        );

        $post = $this->_repository->allPosts()[0];
        $this->assertTrue($post->buttons->isEmpty());
        $this->assertSame([], $post->buttons->toArray());
    }

    public function testTitleSurvivesCreateAndUpdate(): void
    {
        $this->_repository->createDraft('Черновик с заголовком', [], '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), 'Ищем попутчиков');
        $this->_repository->createPost('Пост с заголовком', [], '2026-09-10 21:30:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), 'Ищем попутчиков');

        $this->assertSame('Ищем попутчиков', $this->_repository->allDrafts()[0]->title);
        $this->assertSame('Ищем попутчиков', $this->_repository->allPosts()[0]->title);

        $this->_repository->updateDraft(1, 'Черновик без заголовка', [], '2026-09-10 11:00:00', new MessageEntities(), new LinkButtons(), '');
        $this->_repository->updatePost(1, 'Пост без заголовка', [], '2026-09-10 22:00:00', '2026-09-10 11:00:00', new MessageEntities(), new LinkButtons(), '');

        $this->assertSame('', $this->_repository->allDrafts()[0]->title);
        $this->assertSame('', $this->_repository->allPosts()[0]->title);
    }

    public function testCreatePostsStoresEveryPartInOrder(): void
    {
        $firstId = $this->_repository->createPosts([
            [
                'text' => 'Часть 1',
                'imageUrls' => ['https://example.com/a.png'],
                'formatting' => new MessageEntities(),
                'buttons' => LinkButtons::fromPairs(['Пройти опрос'], ['https://example.com/poll']),
                'title' => 'Ищем попутчиков',
                'publishedAt' => '2026-09-10 21:30:00',
            ],
            [
                'text' => 'Часть 2',
                'imageUrls' => [],
                'formatting' => new MessageEntities(),
                'buttons' => new LinkButtons(),
                'title' => 'Что берём с собой',
                'publishedAt' => '2026-09-10 21:31:00',
            ],
            [
                'text' => 'Часть 3',
                'imageUrls' => [],
                'formatting' => new MessageEntities(),
                'buttons' => new LinkButtons(),
                'title' => '',
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
            [
                [['text' => 'Пройти опрос', 'url' => 'https://example.com/poll']],
                [],
                [],
            ],
            array_map(
                static fn (PublicationData $post): array => $post->buttons->toArray(),
                $due,
            ),
            'Every part must come back with the buttons of its own row.',
        );
        $this->assertSame(
            ['Ищем попутчиков', 'Что берём с собой', ''],
            array_map(static fn (PublicationData $post): string => $post->title, $due),
            'Every part must come back with the heading of its own row.',
        );
    }

    public function testCreateDraftsStoresEveryPart(): void
    {
        $firstId = $this->_repository->createDrafts([
            ['text' => 'Часть 1', 'imageUrls' => ['https://example.com/a.png'], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => 'Ищем попутчиков'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => 'Что берём с собой'],
        ], '2026-09-10 10:00:00');

        $drafts = $this->_repository->allDrafts();

        $this->assertGreaterThan(0, $firstId);
        $this->assertCount(2, $drafts);
        $this->assertContains('Часть 1', array_column($drafts, 'text'));
        $this->assertContains('Часть 2', array_column($drafts, 'text'));
        // Both rows carry the same `updated_at`, which is what the list orders by,
        // so their relative order is the database's — the heading is checked
        // against the text of its own row instead.
        $headingsByPart = array_column($drafts, 'title', 'text');
        ksort($headingsByPart);

        $this->assertSame(
            ['Часть 1' => 'Ищем попутчиков', 'Часть 2' => 'Что берём с собой'],
            $headingsByPart,
            'Every draft part must come back with the heading of its own row.',
        );
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
                    'buttons' => new LinkButtons(),
                    'title' => '',
                    'publishedAt' => '2026-09-10 21:30:00',
                ],
                [
                    'text' => 'Часть 2',
                    'imageUrls' => [],
                    'formatting' => new MessageEntities(),
                    'buttons' => new LinkButtons(),
                    'title' => '',
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
        $this->_repository->createPost('Поздний', [], '2026-09-10 22:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), '');
        $this->_repository->createPost('Ранний', [], '2026-09-10 21:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), '');

        $posts = $this->_repository->allPosts();

        $this->assertSame('Поздний', $posts[0]->text);
        $this->assertSame('Ранний', $posts[1]->text);
    }

    public function testAllDraftsSortedByUpdatedAtDescending(): void
    {
        $this->_repository->createDraft('Первый', [], '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), '');
        $this->_repository->createDraft('Второй', [], '2026-09-10 11:00:00', new MessageEntities(), new LinkButtons(), '');
        $this->_repository->updateDraft(1, 'Первый обновлён', [], '2026-09-10 12:00:00', new MessageEntities(), new LinkButtons(), '');

        $drafts = $this->_repository->allDrafts();

        $this->assertSame('Первый обновлён', $drafts[0]->text);
        $this->assertSame('Второй', $drafts[1]->text);
    }

    public function testFindDueForPublishingReturnsOnlyDueUnpublished(): void
    {
        $this->_repository->createPost('Прошлое', [], '2026-09-09 12:00:00', '2026-09-09 10:00:00', new MessageEntities(), new LinkButtons(), '');
        $this->_repository->createPost('Наступившее', [], '2026-09-10 12:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), '');
        $this->_repository->createPost('Будущее', [], '2026-09-11 12:00:00', '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), '');

        $due = $this->_repository->findDueForPublishing('2026-09-10 20:00:00');

        $this->assertCount(2, $due);
        $this->assertSame('Прошлое', $due[0]->text);
        $this->assertSame('Наступившее', $due[1]->text);
    }

    public function testFindDueSkipsPostsWithTelegramId(): void
    {
        $this->_repository->createPost('Опубликованный', [], '2026-09-09 12:00:00', '2026-09-09 10:00:00', new MessageEntities(), new LinkButtons(), '');
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
            buttons: LinkButtons::fromPairs(['Открыть'], ['https://example.com/open']),
            title: 'Ищем попутчиков',
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
                buttons: $record->buttons,
                title: $record->title,
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
            [['text' => 'Открыть', 'url' => 'https://example.com/open']],
            $posts[0]->buttons->toArray(),
            'A restored record must carry its buttons back into the posts table.',
        );
        $this->assertSame(
            'Ищем попутчиков',
            $posts[0]->title,
            'A restored record must carry its heading back into the posts table.',
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

    public function testCreateDraftsMarksEveryPartOfOneGroup(): void
    {
        $this->_repository->createDrafts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');

        $drafts = $this->_repository->allDrafts();

        $this->assertSame(
            [
                'abababababababababababababababab',
                'abababababababababababababababab',
            ],
            array_column($drafts, 'partGroupId'),
            'Every draft of a split publication must come back with the group it belongs to.',
        );

        // Both rows carry the same `updated_at`, which is what the list orders by,
        // so their relative order is the database's — the place in the group is
        // read against the text of its own row instead.
        $ordersByPart = array_column($drafts, 'partOrder', 'text');
        $totalsByPart = array_column($drafts, 'partGroupTotal', 'text');
        ksort($ordersByPart);
        ksort($totalsByPart);

        $this->assertSame(
            ['Часть 1' => 1, 'Часть 2' => 2],
            $ordersByPart,
            'Every draft of a split publication must come back with the place it holds in the group.',
        );
        $this->assertSame(
            ['Часть 1' => 2, 'Часть 2' => 2],
            $totalsByPart,
            'A draft part must know how many parts its publication has.',
        );
        $this->assertSame(
            [true, true],
            array_column($drafts, 'partReleased'),
            'A draft carries no queue: every part of it stands released, so a later move to the posts table sends it out.',
        );
    }

    public function testCreateDraftsLeavesASingleRowUngrouped(): void
    {
        $this->_repository->createDraft('Одна часть', [], '2026-09-10 10:00:00', new MessageEntities(), new LinkButtons(), '');

        $draft = $this->_repository->allDrafts()[0];

        $this->assertNull($draft->partGroupId);
        $this->assertNull($draft->partOrder);
        $this->assertNull($draft->partGroupTotal);
    }

    public function testPlainPostsOfASplitKeepEveryPartReleasableToTheQueue(): void
    {
        $this->_repository->createPosts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
            ['text' => 'Часть 3', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:32:00'],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');

        $posts = $this->_repository->allPosts();

        $this->assertSame(
            ['abababababababababababababababab', 'abababababababababababababababab', 'abababababababababababababababab'],
            array_column($posts, 'partGroupId'),
            'A split publication is a group even when its parts do not go out one after another.',
        );
        $this->assertSame(
            [true, true, true],
            array_column($posts, 'partReleased'),
            'Only a staged group holds its parts back: a plain one keeps all of them for the queue.',
        );
        $this->assertCount(
            3,
            $this->_repository->findDueForPublishing('2026-09-10 21:35:00'),
            'The queue must still take every part of a plain split publication.',
        );
    }

    public function testGroupTotalCountsThePartsLivingInAnyBlock(): void
    {
        $this->_repository->createStagedGroup([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
            ['text' => 'Часть 3', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:32:00'],
        ], 'abababababababababababababababab', '2026-09-10 10:00:00');

        // The publication breaks into fragments: part 2 goes to drafts, part 3 to
        // the deleted list, and part 1 stays where it was written.
        $second = $this->_repository->findPost(2);
        $this->_repository->deletePost(2);
        $this->_repository->insertDraftWithHistory($second, '2026-09-10 11:00:00');
        $third = $this->_repository->findPost(3);
        $this->_repository->deletePost(3);
        $this->_repository->insertDeletedWithHistory($third, '2026-09-10 11:00:00');

        $this->assertSame(
            [3],
            array_column($this->_repository->allPosts(), 'partGroupTotal'),
            'A part left in the posts table must still count the parts that moved away.',
        );
        $this->assertSame(
            3,
            $this->_repository->allDrafts()[0]->partGroupTotal,
            'A part in drafts must count the whole publication.',
        );
        $this->assertSame(
            3,
            $this->_repository->allDeleted()[0]->partGroupTotal,
            'A part in the deleted list must count the whole publication.',
        );
    }

    public function testFindGroupRowsReturnsEveryLivePartInItsPlace(): void
    {
        $this->_repository->createStagedGroup([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
        ], 'abababababababababababababababab', '2026-09-10 10:00:00');
        $this->_repository->createDrafts([
            ['text' => 'Часть 3', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
            ['text' => 'Чужая часть', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
        ], '2026-09-10 10:00:00', 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd');

        $rows = $this->_repository->findGroupRows('abababababababababababababababab');

        $this->assertSame(
            ['Часть 1', 'Часть 2'],
            array_map(static fn (PublicationData $row): string => $row->text, $rows),
            'Every live part of the group must come back, in the order of its places.',
        );
        $this->assertSame([1, 2], array_column($rows, 'partOrder'));
        $this->assertSame([], $this->_repository->findGroupRows('efefefefefefefefefefefefefefefef'));
    }

    public function testFindGroupRowsReadsTheDraftsAndTheDeletedRowsOfAGroup(): void
    {
        $this->_repository->createDrafts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');
        $draft = $this->_repository->findDraft(2);
        $this->_repository->deleteDraft(2);
        $this->_repository->insertDeletedWithHistory($draft, '2026-09-10 11:00:00');

        $rows = $this->_repository->findGroupRows('abababababababababababababababab');

        $this->assertCount(2, $rows, 'A part living outside the posts table must still reach the form.');
        $this->assertSame(
            ['draft', 'deleted'],
            array_map(static fn (PublicationData $row): string => (string)$row->habitat, $rows),
            'Every part of the group must say which block it lives in, so the form can badge it.',
        );
    }

    public function testWaitingPartKeepsItsStateAcrossTheMoveToDeletedAndBack(): void
    {
        $this->_repository->createStagedGroup([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
        ], 'abababababababababababababababab', '2026-09-10 10:00:00');

        $this->assertTrue($this->_repository->findPost(1)->partReleased);
        $this->assertFalse($this->_repository->findPost(2)->partReleased);

        $second = $this->_repository->findPost(2);
        $this->_repository->deletePost(2);
        $this->_repository->insertDeletedWithHistory($second, '2026-09-10 11:00:00');
        $record = $this->_repository->findDeleted(1);
        $this->assertNotNull($record->partGroupId);
        $this->assertSame(2, $record->partOrder);
        $this->assertFalse($record->partReleased, 'A part held back must stay held back in the deleted list.');

        $this->_repository->deleteDeleted(1);
        $this->_repository->insertPostWithHistory($record, '2026-09-10 12:00:00');

        $releasedByPlace = array_column($this->_repository->allPosts(), 'partReleased', 'partOrder');
        ksort($releasedByPlace);
        $this->assertSame(
            [1 => true, 2 => false],
            $releasedByPlace,
            'A part that comes back to the posts table must not slip into the queue ahead of the part above it.',
        );
    }

    public function testGroupExistsSeesAGroupStoredOutsideThePostsTable(): void
    {
        $this->_repository->createDrafts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');

        $this->assertTrue(
            $this->_repository->groupExists('abababababababababababababababab'),
            'A group that already rests in drafts must not be written over by a stale form.',
        );
        $this->assertFalse($this->_repository->groupExists('efefefefefefefefefefefefefefefef'));
    }

    public function testMovedPartKeepsItsGroupAndPlace(): void
    {
        $this->_repository->createPosts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');

        $first = $this->_repository->findPost(1);
        $this->_repository->deletePost(1);
        $this->_repository->insertDraftWithHistory($first, '2026-09-10 11:00:00');

        $draft = $this->_repository->findDraft(1);
        $this->assertSame('abababababababababababababababab', $draft->partGroupId);
        $this->assertSame(1, $draft->partOrder);
        $this->assertSame(2, $draft->partGroupTotal, 'The draft fragment must count its sibling still in the posts table.');
    }

    public function testCreatePostWritesOneMorePartOfAnExistingGroup(): void
    {
        $id = $this->_repository->createPost(
            'Новая часть',
            [],
            '2026-09-10 21:40:00',
            '2026-09-10 10:00:00',
            new MessageEntities(),
            new LinkButtons(),
            '',
            'abababababababababababababababab',
            3,
            false,
        );

        $post = $this->_repository->findPost($id);
        $this->assertNotNull($post);
        $this->assertSame('abababababababababababababababab', $post->partGroupId);
        $this->assertSame(3, $post->partOrder);
        $this->assertFalse($post->partReleased, 'A part written under a waiting one is held back with it.');
    }

    public function testCreatePostOfItsOwnKeepsTheGroupColumnsEmpty(): void
    {
        $id = $this->_repository->createPost(
            'Просто публикация',
            [],
            '2026-09-10 21:40:00',
            '2026-09-10 10:00:00',
            new MessageEntities(),
            new LinkButtons(),
            '',
        );

        $post = $this->_repository->findPost($id);
        $this->assertNotNull($post);
        $this->assertNull($post->partGroupId, 'A publication of its own claims no group.');
        $this->assertNull($post->partOrder);
    }

    public function testCreateDraftWritesOneMorePartOfAnExistingGroup(): void
    {
        $id = $this->_repository->createDraft(
            'Новая часть',
            [],
            '2026-09-10 10:00:00',
            new MessageEntities(),
            new LinkButtons(),
            '',
            'abababababababababababababababab',
            2,
        );

        $draft = $this->_repository->findDraft($id);
        $this->assertNotNull($draft);
        $this->assertSame('abababababababababababababababab', $draft->partGroupId);
        $this->assertSame(2, $draft->partOrder);
    }

    public function testUpdatePostRewritesThePlaceOfAPart(): void
    {
        $this->_repository->createPosts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');

        $this->_repository->updatePost(
            1,
            'Первая часть переписана',
            [],
            '2026-09-10 21:31:00',
            '2026-09-10 11:00:00',
            new MessageEntities(),
            new LinkButtons(),
            '',
            2,
        );
        $this->_repository->updatePost(
            2,
            'Вторая часть переписана',
            [],
            '2026-09-10 21:30:00',
            '2026-09-10 11:00:00',
            new MessageEntities(),
            new LinkButtons(),
            '',
        );

        $moved = $this->_repository->findPost(1);
        $this->assertNotNull($moved);
        $this->assertSame(2, $moved->partOrder, 'The form gives a part the place it stands in.');
        $this->assertSame('abababababababababababababababab', $moved->partGroupId);

        $untouched = $this->_repository->findPost(2);
        $this->assertNotNull($untouched);
        $this->assertSame(
            2,
            $untouched->partOrder,
            'A save that names no place leaves the row of a part where it stands.',
        );
    }

    public function testUpdateDraftRewritesThePlaceOfAPart(): void
    {
        $this->_repository->createDrafts([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => ''],
        ], '2026-09-10 10:00:00', 'abababababababababababababababab');

        $this->_repository->updateDraft(
            2,
            'Вторая часть переписана',
            [],
            '2026-09-10 11:00:00',
            new MessageEntities(),
            new LinkButtons(),
            '',
            1,
        );

        $moved = $this->_repository->findDraft(2);
        $this->assertNotNull($moved);
        $this->assertSame(1, $moved->partOrder);
        $this->assertSame('abababababababababababababababab', $moved->partGroupId);
        $this->assertSame('2026-09-10 10:00:00', $moved->createdAt);
    }

    public function testReleasePartOpensTheRowThatWaitsAndNobodyBelowIt(): void
    {
        $this->_repository->createStagedGroup([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
            ['text' => 'Часть 3', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:32:00'],
        ], 'abababababababababababababababab', '2026-09-10 10:00:00');

        $this->_repository->releasePart('abababababababababababababababab', 2, '2026-09-10 21:35:00', '2026-09-10 11:00:00');

        $second = $this->_repository->findPost(2);
        $this->assertNotNull($second);
        $this->assertTrue($second->partReleased, 'The release opens the queue of the row it names.');
        $this->assertSame('2026-09-10 21:35:00', $second->publishedAt, 'The row takes the time the reader gave it.');
        $this->assertSame('2026-09-10 11:00:00', $second->updatedAt);

        $releasedByPlace = array_column($this->_repository->allPosts(), 'partReleased', 'partOrder');
        ksort($releasedByPlace);
        $this->assertSame(
            [1 => true, 2 => true, 3 => false],
            $releasedByPlace,
            'A part below a newly released one waits for it in its turn.',
        );

        $due = $this->_repository->findDueForPublishing('2026-09-10 21:40:00');
        $this->assertSame(
            ['Часть 1', 'Часть 2'],
            array_map(static fn (PublicationData $post): string => $post->text, $due),
            'The queue reads the released part and leaves the one still held back.',
        );
    }

    public function testReleasePartRefusesARowTheQueueAlreadyHolds(): void
    {
        $this->_repository->createStagedGroup([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
        ], 'abababababababababababababababab', '2026-09-10 10:00:00');

        $this->_repository->releasePart('abababababababababababababababab', 2, '2026-09-10 21:35:00', '2026-09-10 11:00:00');

        foreach ([1, 2] as $place) {
            try {
                $this->_repository->releasePart('abababababababababababababababab', $place, '2026-09-10 22:00:00', '2026-09-10 12:00:00');
                $this->fail('Часть на месте ' . $place . ' уже стоит в очереди и должна была быть отказана.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Часть уже поставлена в очередь.', $e->getMessage());
            }
        }

        $second = $this->_repository->findPost(2);
        $this->assertNotNull($second);
        $this->assertSame(
            '2026-09-10 21:35:00',
            $second->publishedAt,
            'A refused release must not move the time of the row it refused.',
        );
    }

    public function testArchivingAnEditedPartKeepsItsGroupAndItsPlace(): void
    {
        $this->_repository->createStagedGroup([
            ['text' => 'Часть 1', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:30:00'],
            ['text' => 'Часть 2', 'imageUrls' => [], 'formatting' => new MessageEntities(), 'buttons' => new LinkButtons(), 'title' => '', 'publishedAt' => '2026-09-10 21:31:00'],
        ], 'abababababababababababababababab', '2026-09-10 10:00:00');

        $first = $this->_repository->findPost(1);
        $this->assertNotNull($first);
        $this->_repository->archiveEdited($first, '2026-09-10 12:00:00');

        $archived = Yii::$app->getDb()
            ->createCommand('SELECT part_group_id, part_order FROM {{%publications_edited}} ORDER BY id ASC')
            ->queryAll();

        $this->assertCount(1, $archived);
        $this->assertSame(
            'abababababababababababababababab',
            $archived[0]['part_group_id'],
            'The archived copy of an edited part says which publication it was a part of.',
        );
        $this->assertSame(
            1,
            (int)$archived[0]['part_order'],
            'The archived copy keeps the place the part held in its group.',
        );
    }
}
