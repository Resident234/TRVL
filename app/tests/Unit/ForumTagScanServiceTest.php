<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\shared\Forum\Contract\TagRepositoryInterface;
use app\shared\Forum\Service\CountryTagExtractor;
use app\shared\Forum\Service\ForumTagScanService;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

final class ForumTagScanServiceTest extends Unit
{
    private TagRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;

    protected function _before(): void
    {
        $this->_repository = $this->createMock(TagRepositoryInterface::class);
    }

    private function createService(): ForumTagScanService
    {
        return new ForumTagScanService(
            $this->_repository,
            new CountryTagExtractor(),
            new NullLogger(),
        );
    }

    public function testRunTagsForumsAndCountsUntaggedOnes(): void
    {
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_repository->method('forums')->willReturn([
            ['id' => 158, 'title' => 'ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании'],
            ['id' => 826, 'title' => 'ЕВРОПА форум'],
        ]);
        $calls = [];
        $this->_repository->expects($this->exactly(2))->method('saveForumTags')->willReturnCallback(
            static function (int $forumId, array $tagNames, string $now) use (&$calls): array {
                $calls[] = [$forumId, $tagNames];
                return $forumId === 158
                    ? ['tags_created' => 1, 'links_created' => 1]
                    : ['tags_created' => 0, 'links_created' => 0];
            },
        );

        $stats = $this->createService()->run();

        $this->assertSame([[158, ['испания']], [826, []]], $calls);
        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['tagged']);
        $this->assertSame(1, $stats['untagged']);
        $this->assertSame(1, $stats['tags_created']);
        $this->assertSame(1, $stats['links_created']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testRunPassesRangeAndLimitToRepository(): void
    {
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_repository->expects($this->once())
            ->method('forums')
            ->with(150, 160, 5)
            ->willReturn([]);

        $stats = $this->createService()->run(150, 160, 5);

        $this->assertSame(0, $stats['processed']);
    }

    public function testRunContinuesAfterAForumFailure(): void
    {
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_repository->method('forums')->willReturn([
            ['id' => 158, 'title' => 'ИСПАНИЯ форум'],
            ['id' => 159, 'title' => 'ИТАЛИЯ форум'],
        ]);
        $this->_repository->method('saveForumTags')->willReturnCallback(
            static function (int $forumId): array {
                if ($forumId === 158) {
                    throw new \RuntimeException('database error');
                }
                return ['tags_created' => 1, 'links_created' => 1];
            },
        );

        $stats = $this->createService()->run();

        $this->assertSame(2, $stats['processed']);
        $this->assertSame(1, $stats['tagged']);
        $this->assertSame(0, $stats['untagged']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['tags_created']);
    }

    public function testRunSkipsWhenLockIsHeld(): void
    {
        $this->_repository->method('acquireLock')->willReturn(false);
        $this->_repository->expects($this->never())->method('forums');
        $this->_repository->expects($this->never())->method('releaseLock');

        $this->assertNull($this->createService()->run());
    }
}
