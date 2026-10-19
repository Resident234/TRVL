<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;
use InvalidArgumentException;

/**
 * The one call that starts a part after the first one: a single waiting row goes
 * into the queue with the time the reader gives it, while a row the table does
 * not hold, a row that has gone already, a row behind a neighbour that has not
 * reached the channel and a row whose time does not follow that neighbour are
 * all refused. The time is normalised before it reaches the row, the way every
 * other write of a moment does.
 */
final class PublicationsServiceReleasePartTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ScriptedChannelStub $_channel;

    /** What the repository was asked to release. @var array<int, array<int, mixed>> */
    private array $_releases = [];

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, $this->_channel);
    }

    /**
     * A part of the group as its table hands it over: `released` says whether the
     * queue may already take it, `releasable` says whether the part above this one
     * has reached the channel.
     */
    private function part(int $order, bool $released, bool $releasable, string $at): PublicationData
    {
        return new PublicationData(
            $order,
            'Часть ' . $order,
            [],
            null,
            $at,
            '2026-10-04 07:00:00',
            '2026-10-04 07:30:00',
            null,
            new MessageEntities(),
            new LinkButtons(),
            '',
            self::GROUP,
            $order,
            $released,
            $releasable,
        );
    }

    /**
     * The parts of one group as the table reads them, in the order of their places.
     *
     * @param PublicationData[] $parts
     */
    private function hold(array $parts): void
    {
        $this->_repository->method('findPartGroup')->willReturn($parts);
    }

    /**
     * The row the queue must be told about: the release is recorded with the group,
     * the place and the moment that has gone into it.
     */
    private function expectRelease(): void
    {
        $this->_repository
            ->expects($this->once())
            ->method('releasePart')
            ->willReturnCallback(
                function (string $groupId, int $order, string $at, string $now): void {
                    $this->_releases[] = [$groupId, $order, $at];
                },
            );
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_channel = new ScriptedChannelStub([]);
    }

    public function testTheWaitingPartNextInLineGoesIntoTheQueue(): void
    {
        $this->hold([
            $this->part(1, true, false, '2026-10-04 08:00:00'),
            $this->part(2, false, true, '2026-10-04 08:50:00'),
        ]);
        $this->expectRelease();

        $this->service()->releasePart(self::GROUP, 2, '2026-10-04 09:30:00', 'UTC');

        $this->assertSame(
            [[self::GROUP, 2, '2026-10-04 09:30:00']],
            $this->_releases,
            'A part goes into the queue under its own group and its own place, with the time it was given.',
        );
    }

    public function testATimeTypedInAnotherZoneReachesTheRowInUtc(): void
    {
        $this->hold([
            $this->part(1, true, false, '2026-10-04 06:00:00'),
            $this->part(2, false, true, ''),
        ]);
        $this->expectRelease();

        $this->service()->releasePart(self::GROUP, 2, '2026-10-04 09:30:00', 'Europe/Moscow');

        $this->assertSame(
            '2026-10-04 06:30:00',
            $this->_releases[0][2],
            'The moment is read in the zone of the reader and stored in UTC, as every other write of a time does.',
        );
    }

    public function testAPartTheTableDoesNotHoldRefuses(): void
    {
        $this->hold([
            $this->part(1, true, false, '2026-10-04 08:00:00'),
            $this->part(2, false, true, '2026-10-04 08:50:00'),
        ]);
        $this->_repository->expects($this->never())->method('releasePart');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Часть публикации не найдена.');

        $this->service()->releasePart(self::GROUP, 3, '2026-10-04 09:30:00', 'UTC');
    }

    public function testTheFirstPartOfAGroupIsAlreadyInTheQueue(): void
    {
        $this->hold([
            $this->part(1, true, false, '2026-10-04 08:00:00'),
            $this->part(2, false, true, '2026-10-04 08:50:00'),
        ]);
        $this->_repository->expects($this->never())->method('releasePart');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Часть уже поставлена в очередь.');

        $this->service()->releasePart(self::GROUP, 1, '2026-10-04 09:30:00', 'UTC');
    }

    public function testAPartBehindANeighbourThatHasNotReachedTheChannelRefuses(): void
    {
        $this->hold([
            $this->part(1, false, false, '2026-10-04 08:00:00'),
            $this->part(2, false, false, '2026-10-04 08:50:00'),
        ]);
        $this->_repository->expects($this->never())->method('releasePart');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Сначала нужно опубликовать предыдущую часть.');

        $this->service()->releasePart(self::GROUP, 2, '2026-10-04 09:30:00', 'UTC');
    }

    public function testATimeThatDoesNotFollowThePartAboveRefuses(): void
    {
        $this->hold([
            $this->part(1, true, false, '2026-10-04 09:30:00'),
            $this->part(2, false, true, ''),
        ]);
        $this->_repository->expects($this->never())->method('releasePart');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Время части должно быть позже времени предыдущей части.');

        $this->service()->releasePart(self::GROUP, 2, '2026-10-04 09:30:00', 'UTC');
    }
}
