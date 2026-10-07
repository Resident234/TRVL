<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Infrastructure;

use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Infrastructure\PublicationRepository;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;
use Yii;

/**
 * The paging the scroll of a block reads from: a page is a window of rows, the
 * offset counts rows and not pages, and the whole list is the sum of its pages
 * in the order the block stands. The page script trusts all three — its offset
 * is the number of rows on screen and its end is the number `count*()` gives.
 */
final class PublicationRepositoryPagingTest extends Unit
{
    private PublicationRepository $_repository;

    protected function _before(): void
    {
        parent::_before();

        foreach (['{{%publications_draft}}', '{{%publications_post}}', '{{%publications_deleted}}'] as $table) {
            Yii::$app->getDb()
                ->createCommand('TRUNCATE TABLE ' . $table . ' RESTART IDENTITY')
                ->execute();
        }
        $this->_repository = new PublicationRepository(Yii::$app->getDb());
    }

    public function testPagesOfATenReadTheListInOrderAndLeaveNothingOut(): void
    {
        $this->seedPosts(12);

        $first = $this->_repository->allPosts(10, 0);
        $second = $this->_repository->allPosts(10, 10);

        $this->assertCount(10, $first);
        $this->assertCount(2, $second);
        $this->assertSame('Пост 12', $this->texts($first)[0]);
        $this->assertSame('Пост 1', $this->texts($second)[1]);
        $this->assertSame([], array_values(array_intersect($this->texts($first), $this->texts($second))));
        $this->assertSame($this->texts($this->_repository->allPosts()), array_merge($this->texts($first), $this->texts($second)));
    }

    public function testAnOffsetCountsRowsAndNotPages(): void
    {
        $this->seedPosts(12);

        $window = $this->_repository->allPosts(5, 4);

        $this->assertSame(['Пост 8', 'Пост 7', 'Пост 6', 'Пост 5', 'Пост 4'], $this->texts($window));
    }

    public function testThePageAfterAnExactMultipleIsEmpty(): void
    {
        $this->seedPosts(10);

        $this->assertSame([], $this->texts($this->_repository->allPosts(10, 10)));
        $this->assertSame([], $this->texts($this->_repository->allPosts(10, 500)));
        $this->assertSame(10, $this->_repository->countPosts());
    }

    public function testAZeroLimitReadsTheWholeListAtOnce(): void
    {
        $this->seedPosts(12);

        $this->assertSame($this->texts($this->_repository->allPosts(10, 0)), array_slice($this->texts($this->_repository->allPosts(0)), 0, 10));
        $this->assertCount(12, $this->_repository->allPosts(0, 0));
    }

    public function testOldestFirstPagesMirrorTheNewestOrder(): void
    {
        $this->seedPosts(12);

        $newest = $this->texts($this->_repository->allPosts(0, 0, false));
        $oldest = array_merge(
            $this->texts($this->_repository->allPosts(10, 0, true)),
            $this->texts($this->_repository->allPosts(10, 10, true)),
        );

        $this->assertSame(array_reverse($newest), $oldest);
        $this->assertSame('Пост 1', $oldest[0]);
        $this->assertCount(10, $this->_repository->allPosts(10, 0, true));
    }

    public function testRowsOfTheSameMomentKeepTheIdentifierTiebreaker(): void
    {
        $moment = '2026-09-01 10:00:00';
        foreach ([1, 2, 3] as $number) {
            $this->_repository->createPost(
                'Пост ' . $number,
                [],
                $moment,
                $moment,
                new MessageEntities(),
                new LinkButtons(),
                '',
            );
        }

        $this->assertSame(
            ['Пост 3', 'Пост 2', 'Пост 1'],
            $this->texts($this->_repository->allPosts(0, 0, false)),
        );
        $this->assertSame(
            ['Пост 1', 'Пост 2', 'Пост 3'],
            $this->texts($this->_repository->allPosts(0, 0, true)),
        );

        // A tie cut in half by a page boundary still hands over every row once.
        $pages = array_merge(
            $this->texts($this->_repository->allPosts(2, 0)),
            $this->texts($this->_repository->allPosts(2, 2)),
        );
        $this->assertSame($this->texts($this->_repository->allPosts()), $pages);
    }

    public function testDraftsArePagedOverUpdatedAt(): void
    {
        $this->seedDrafts(12);

        $pages = array_merge(
            $this->texts($this->_repository->allDrafts(5, 0)),
            $this->texts($this->_repository->allDrafts(5, 5)),
            $this->texts($this->_repository->allDrafts(5, 10)),
        );

        $this->assertCount(12, $this->_repository->allDrafts());
        $this->assertSame('Черновик 1', $this->texts($this->_repository->allDrafts(10, 0))[0]);
        $this->assertSame($this->texts($this->_repository->allDrafts()), $pages);
        $this->assertSame(12, $this->_repository->countDrafts());
    }

    public function testDeletedRecordsArePagedOverUpdatedAt(): void
    {
        $this->seedDeleted(12);

        $pages = array_merge(
            $this->texts($this->_repository->allDeleted(10, 0)),
            $this->texts($this->_repository->allDeleted(10, 10)),
        );

        $this->assertSame('Удалённый 1', $this->texts($this->_repository->allDeleted(10, 0))[0]);
        $this->assertSame($this->texts($this->_repository->allDeleted()), $pages);
        $this->assertSame([], $this->texts($this->_repository->allDeleted(10, 20)));
        $this->assertSame(12, $this->_repository->countDeleted());
    }

    public function testTheCountIsTheNumberOfRowsThePagesHandOut(): void
    {
        $this->seedPosts(7);
        $this->seedDrafts(11);
        $this->seedDeleted(10);

        // The page stops asking when its offset reaches these numbers, so a
        // count that disagrees with the list either cuts rows or loops.
        $this->assertSame(7, $this->_repository->countPosts());
        $this->assertSame(7, count($this->_repository->allPosts(10, 0)));
        $this->assertSame(11, $this->_repository->countDrafts());
        $this->assertSame(11, count($this->_repository->allDrafts(10, 0)) + count($this->_repository->allDrafts(10, 10)));
        $this->assertSame(10, $this->_repository->countDeleted());
        $this->assertSame(10, count($this->_repository->allDeleted(10, 0)) + count($this->_repository->allDeleted(10, 10)));
    }

    private function seedPosts(int $count): void
    {
        $start = strtotime('2026-09-01 10:00:00');

        for ($number = 1; $number <= $count; $number += 1) {
            // The moment the post is due and the moment the row was written run
            // opposite to each other: only the first one orders the block.
            $this->_repository->createPost(
                'Пост ' . $number,
                [],
                gmdate('Y-m-d H:i:s', (int)$start + $number * 300),
                gmdate('Y-m-d H:i:s', (int)$start - $number * 300),
                new MessageEntities(),
                new LinkButtons(),
                '',
            );
        }
    }

    private function seedDrafts(int $count): void
    {
        $start = strtotime('2026-09-01 10:00:00');

        for ($number = 1; $number <= $count; $number += 1) {
            $this->_repository->createDraft(
                'Черновик ' . $number,
                [],
                gmdate('Y-m-d H:i:s', (int)$start + $number * 300),
                new MessageEntities(),
                new LinkButtons(),
                '',
            );
        }

        // Written oldest first, touched newest first: the block reads the touch.
        for ($number = 1; $number <= $count; $number += 1) {
            $this->_repository->updateDraft(
                $number,
                'Черновик ' . $number,
                [],
                gmdate('Y-m-d H:i:s', (int)$start - $number * 300),
                new MessageEntities(),
                new LinkButtons(),
                '',
            );
        }
    }

    /**
     * The way a record really reaches the archive: the post is read, dropped
     * and stored again with the moment it left.
     */
    private function seedDeleted(int $count): void
    {
        $start = strtotime('2026-09-01 10:00:00');

        for ($number = 1; $number <= $count; $number += 1) {
            $this->_repository->createPost(
                'Удалённый ' . $number,
                [],
                '2026-08-01 10:00:00',
                '2026-08-01 10:00:00',
                new MessageEntities(),
                new LinkButtons(),
                '',
            );
            $moved = $this->_repository->deletePost($number);
            $this->_repository->insertDeletedWithHistory(
                $moved,
                gmdate('Y-m-d H:i:s', (int)$start - $number * 300),
            );
        }
    }

    /**
     * @param PublicationData[] $records
     * @return list<string>
     */
    private function texts(array $records): array
    {
        return array_values(array_map(
            static fn (PublicationData $record): string => (string)$record->text,
            $records,
        ));
    }
}
