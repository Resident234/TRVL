<?php

declare(strict_types=1);

namespace app\tests\Unit;

use app\shared\Forum\Contract\ForumHttpClientInterface;
use app\shared\Forum\Contract\ForumRepositoryInterface;
use app\shared\Forum\Dto\ForumData;
use app\shared\Forum\Infrastructure\ForumPageNotFoundException;
use app\shared\Forum\Service\ForumSectionPageParser;
use app\shared\Forum\Service\ForumSectionScanService;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

final class ForumSectionScanServiceTest extends Unit
{
    private const CONFIG = [
        'id' => '1',
        'code' => 'awd_forum_sections',
        'base_url' => 'https://forum.awd.ru/viewforum.php?f=',
        't_from' => '157',
        't_to' => '159',
        'is_active' => '1',
    ];

    private const SECTION_HTML = <<<'HTML'
<html><body>
<h1>ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании</h1>
</body></html>
HTML;

    private ForumRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ForumHttpClientInterface&\PHPUnit\Framework\MockObject\MockObject $_httpClient;

    protected function _before(): void
    {
        $this->_repository = $this->createMock(ForumRepositoryInterface::class);
        $this->_httpClient = $this->createMock(ForumHttpClientInterface::class);
    }

    private function createService(): ForumSectionScanService
    {
        return new ForumSectionScanService(
            $this->_repository,
            $this->_httpClient,
            new ForumSectionPageParser(),
            new NullLogger(),
        );
    }

    public function testRunSavesSectionsAndSkipsFailures(): void
    {
        $this->_repository->method('activeConfig')->willReturn(self::CONFIG);
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_repository->method('saveForum')->willReturn(true, false);
        $this->_repository->expects($this->once())->method('markRun');

        $this->_httpClient->method('get')->willReturnCallback(
            static function (string $url): string {
                return match (true) {
                    str_ends_with($url, '=157'), str_ends_with($url, '=159') => self::SECTION_HTML,
                    str_ends_with($url, '=158') => throw new ForumPageNotFoundException('missing'),
                    default => throw new \RuntimeException('unexpected ' . $url),
                };
            },
        );

        $stats = $this->createService()->run();

        $this->assertSame(3, $stats['processed']);
        $this->assertSame(1, $stats['saved']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $stats['not_found']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testRunSavesForumIdUrlAndTitle(): void
    {
        $config = array_merge(self::CONFIG, ['t_from' => '158', 't_to' => '158']);
        $this->_repository->method('activeConfig')->willReturn($config);
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_httpClient->method('get')->willReturn(self::SECTION_HTML);
        $this->_repository->expects($this->once())->method('saveForum')->with(
            $this->callback(static function (ForumData $forum): bool {
                return $forum->id === 158
                    && $forum->sourceUrl === 'https://forum.awd.ru/viewforum.php?f=158'
                    && $forum->title === 'ИСПАНИЯ форум — отдых, путешествия, города и пляжи Испании';
            }),
        )->willReturn(true);

        $stats = $this->createService()->run();

        $this->assertSame(1, $stats['saved']);
    }

    public function testRunCountsLoginRequiredSections(): void
    {
        $config = array_merge(self::CONFIG, ['t_from' => '158', 't_to' => '158']);
        $this->_repository->method('activeConfig')->willReturn($config);
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_repository->expects($this->never())->method('saveForum');
        $this->_httpClient->method('get')->willReturn(
            '<html><body><h1></h1>Для просмотра этого форума вы должны быть авторизованы</body></html>',
        );

        $stats = $this->createService()->run();

        $this->assertSame(1, $stats['login_required']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testRunRespectsLimit(): void
    {
        $this->_repository->method('activeConfig')->willReturn(self::CONFIG);
        $this->_repository->method('acquireLock')->willReturn(true);
        $this->_repository->method('saveForum')->willReturn(true);
        $this->_httpClient->method('get')->willReturn(self::SECTION_HTML);

        $stats = $this->createService()->run(null, null, 1);

        $this->assertSame(1, $stats['processed']);
        $this->assertSame(1, $stats['saved']);
    }

    public function testRunSkipsWhenLockIsHeld(): void
    {
        $this->_repository->method('acquireLock')->willReturn(false);
        $this->_repository->expects($this->never())->method('saveForum');
        $this->_repository->expects($this->never())->method('markRun');
        $this->_repository->expects($this->never())->method('releaseLock');

        $this->assertNull($this->createService()->run());
    }

    public function testRunDoesNothingWithoutConfig(): void
    {
        $this->_repository->method('activeConfig')->willReturn(null);
        $this->_repository->method('acquireLock')->willReturn(true);

        $stats = $this->createService()->run();

        $this->assertSame(0, $stats['processed']);
        $this->assertSame(0, $stats['saved']);
    }
}
