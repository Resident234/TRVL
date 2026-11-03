<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Service\PublicationsService;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;
use InvalidArgumentException;

/**
 * The «Рандомное время» box of the form: the server deals one throw per part
 * inside the named range at the moment the rows are written, and the throws
 * come back lined up behind the first part, since a part may never go out
 * before the one above it.
 */
final class PublicationsServiceRandomTimesTest extends Unit
{
    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ScriptedChannelStub $_channel;

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, $this->_channel);
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_channel = new ScriptedChannelStub([]);
    }

    public function testRandomTimesStayInsideTheRangeAndNeverGoBackwards(): void
    {
        $times = $this->service()->randomTimes(
            5,
            '2026-10-04 08:00:00',
            '2026-10-04 20:00:00',
            'UTC',
        );

        $this->assertCount(5, $times, 'Every part draws a throw of its own.');
        foreach ($times as $index => $time) {
            $this->assertGreaterThanOrEqual('2026-10-04 08:00:00', $time);
            $this->assertLessThanOrEqual('2026-10-04 20:00:00', $time);
            if ($index > 0) {
                $this->assertGreaterThanOrEqual(
                    $times[$index - 1],
                    $time,
                    'The sequence of the publication is never shuffled.',
                );
            }
        }
    }

    public function testRandomTimesReadsTheEndsAsWallClockTime(): void
    {
        // Two UTC hours are the whole wall-clock range of a reader six hours
        // ahead: every throw is written inside it, in UTC as the tables are.
        $times = $this->service()->randomTimes(
            3,
            '2026-10-04 13:00:00',
            '2026-10-04 15:00:00',
            'Asia/Omsk',
        );

        foreach ($times as $time) {
            $this->assertGreaterThanOrEqual('2026-10-04 07:00:00', $time);
            $this->assertLessThanOrEqual('2026-10-04 09:00:00', $time);
        }
    }

    public function testARangeThatEndsBeforeItStartsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Конец диапазона случайного времени должен быть позже его начала.');

        $this->service()->randomTimes(2, '2026-10-04 20:00:00', '2026-10-04 08:00:00', 'UTC');
    }

    public function testRelativeTimesLandBetweenNowAndTheNamedMoment(): void
    {
        $before = time();
        $times = $this->service()->randomRelativeTimes(4, 2, 'weeks');
        $after = time();

        $this->assertCount(4, $times);
        foreach ($times as $index => $time) {
            $this->assertGreaterThanOrEqual(gmdate('Y-m-d H:i:s', $before), $time);
            $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s', $after + 2 * 7 * 24 * 3600), $time);
            if ($index > 0) {
                $this->assertGreaterThanOrEqual($times[$index - 1], $time);
            }
        }
    }

    public function testARelativeRangeNeedsACountAndAKnownUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->randomRelativeTimes(2, 0, 'weeks');
    }

    public function testARelativeRangeRefusesAnUnknownUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->randomRelativeTimes(2, 3, 'years');
    }

    public function testASinglePartIsWrittenWithTheThrowItWasDealt(): void
    {
        $written = null;
        $this->_repository
            ->expects($this->once())
            ->method('createPost')
            ->willReturnCallback(
                static function (
                    string $text,
                    array $images,
                    string $publishedAt,
                    string $now
                ) use (&$written): int {
                    $written = $publishedAt;

                    return 1;
                },
            );

        $this->service()->saveParts(
            ['Одна часть'],
            [[]],
            [new MessageEntities()],
            [LinkButtons::empty()],
            [''],
            [new MessageEntities()],
            '',
            'publish',
            null,
            'UTC',
            false,
            [],
            '',
            ['2026-10-05 10:00:00'],
        );

        $this->assertSame('2026-10-05 10:00:00', $written);
    }

    public function testEveryPartIsWrittenWithItsOwnThrowInOrder(): void
    {
        $this->_repository->method('groupExists')->willReturn(false);

        $rows = null;
        $this->_repository
            ->expects($this->once())
            ->method('createPosts')
            ->willReturnCallback(
                static function (array $written, string $now, string $groupId = '') use (&$rows): int {
                    $rows = $written;

                    return 1;
                },
            );

        $this->service()->saveParts(
            ['Первая часть', 'Вторая часть'],
            [[], []],
            [new MessageEntities(), new MessageEntities()],
            [LinkButtons::empty(), LinkButtons::empty()],
            ['', ''],
            [new MessageEntities(), new MessageEntities()],
            '',
            'publish',
            null,
            'UTC',
            false,
            [],
            '',
            ['2026-10-05 18:00:00', '2026-10-05 10:00:00'],
        );

        $this->assertSame('2026-10-05 18:00:00', $rows[0]['publishedAt']);
        $this->assertSame('2026-10-05 10:00:00', $rows[1]['publishedAt']);
    }

    public function testASaveFromTheFormPlansTheRecordByTheThrow(): void
    {
        $draft = new \app\shared\Publications\Dto\PublicationData(
            3,
            'Черновик',
            [],
            null,
            null,
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
        );
        $this->_repository->method('findDraft')->willReturn($draft);
        $this->_repository->method('deleteDraft')->willReturn($draft);

        $carried = null;
        $this->_repository
            ->expects($this->once())
            ->method('insertPostWithHistory')
            ->willReturnCallback(
                static function ($record, string $now) use (&$carried): int {
                    $carried = $record;

                    return 9;
                },
            );

        $this->service()->saveFromForm(
            'Черновик',
            [],
            new MessageEntities(),
            LinkButtons::empty(),
            '',
            new MessageEntities(),
            '',
            'draft',
            3,
            'publish',
            'UTC',
            ['2026-10-05 12:00:00'],
        );

        $this->assertSame('2026-10-05 12:00:00', (string)$carried->publishedAt);
    }
}
