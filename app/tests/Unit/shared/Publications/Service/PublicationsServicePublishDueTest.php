<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use Codeception\Test\Unit;

final class PublicationsServicePublishDueTest extends Unit
{
    private const FIRST = 'Первая запись';
    private const SECOND = 'Вторая запись';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ScriptedChannelStub $_channel;

    private function service(int $attempts = 3): PublicationsService
    {
        return new PublicationsService(
            $this->_repository,
            null,
            $this->_channel,
            publishAttempts: $attempts,
            publishRetryMicroseconds: 0,
        );
    }

    private function post(int $id, string $text): PublicationData
    {
        return new PublicationData(
            $id,
            $text,
            [],
            null,
            '2026-10-04 08:50:00',
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
        );
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
    }

    public function testAPostReachingTheChannelAtOnceIsAskedOnce(): void
    {
        $this->_channel = new ScriptedChannelStub([]);

        $this->_repository
            ->method('findDueForPublishing')
            ->willReturn([$this->post(41, self::FIRST)]);

        $this->_repository
            ->expects($this->once())
            ->method('storeTelegramId')
            ->with(41, 5000, $this->anything(), $this->anything());

        $this->assertSame(
            ['processed' => 1, 'published' => 1, 'failed' => 0],
            $this->service()->publishDue(),
        );
        $this->assertSame(1, $this->_channel->attempts[self::FIRST]);
    }

    public function testAPostRefusedTwiceIsRetriedAndThenPublished(): void
    {
        $this->_channel = new ScriptedChannelStub([self::FIRST => 2]);

        $this->_repository
            ->method('findDueForPublishing')
            ->willReturn([$this->post(41, self::FIRST)]);

        $this->_repository
            ->expects($this->once())
            ->method('storeTelegramId')
            ->with(41, 5000, $this->anything(), $this->anything());

        $this->assertSame(
            ['processed' => 1, 'published' => 1, 'failed' => 0],
            $this->service()->publishDue(),
        );
        $this->assertSame(3, $this->_channel->attempts[self::FIRST]);
    }

    public function testAPostRefusedEveryTimeIsGivenTheAttemptsAndSkipped(): void
    {
        $this->_channel = new ScriptedChannelStub([self::FIRST => 9]);

        $this->_repository
            ->method('findDueForPublishing')
            ->willReturn([$this->post(41, self::FIRST)]);

        $this->_repository->expects($this->never())->method('storeTelegramId');

        $this->assertSame(
            ['processed' => 1, 'published' => 0, 'failed' => 1],
            $this->service()->publishDue(),
        );
        $this->assertSame(3, $this->_channel->attempts[self::FIRST]);
    }

    public function testThePostsBehindAFailingOneStillGoOut(): void
    {
        $this->_channel = new ScriptedChannelStub([self::FIRST => 9]);

        $this->_repository
            ->method('findDueForPublishing')
            ->willReturn([$this->post(41, self::FIRST), $this->post(42, self::SECOND)]);

        $this->_repository
            ->expects($this->once())
            ->method('storeTelegramId')
            ->with(42, 5000, $this->anything(), $this->anything());

        $this->assertSame(
            ['processed' => 2, 'published' => 1, 'failed' => 1],
            $this->service()->publishDue(),
        );
        $this->assertSame(3, $this->_channel->attempts[self::FIRST]);
        $this->assertSame(1, $this->_channel->attempts[self::SECOND]);
    }
}
