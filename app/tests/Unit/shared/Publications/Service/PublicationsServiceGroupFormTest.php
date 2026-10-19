<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use Codeception\Test\Unit;

/**
 * The parts a form takes when one of them is opened for editing: the whole
 * publication as it still lives in the publications and the drafts blocks, and
 * of the deleted block only the very row the reader clicked.
 */
final class PublicationsServiceGroupFormTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, new ScriptedChannelStub([]));
    }

    private function row(
        int $id,
        int $order,
        string $habitat,
        ?string $telegramId = null,
    ): PublicationData {
        return new PublicationData(
            $id,
            'Часть ' . $order,
            [],
            $telegramId === null ? null : (int)$telegramId,
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
            null,
            partGroupId: self::GROUP,
            partOrder: $order,
            habitat: $habitat,
        );
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
    }

    public function testAPartOpenedFromThePostsBlockTakesEveryLivePartOfItsGroup(): void
    {
        $this->_repository
            ->method('findGroupRows')
            ->willReturn([
                $this->row(11, 1, 'post'),
                $this->row(12, 2, 'draft'),
                $this->row(13, 3, 'deleted'),
                $this->row(14, 4, 'deleted'),
            ]);

        $this->assertSame(
            [11, 12],
            array_map(
                static fn (PublicationData $row): int => $row->id,
                $this->service()->formRowsOfGroup(self::GROUP, 'post', 11),
            ),
        );
    }

    public function testAPartOpenedFromTheDeletedBlockTakesTheLivePartsAndOnlyItsOwnRow(): void
    {
        $this->_repository
            ->method('findGroupRows')
            ->willReturn([
                $this->row(11, 1, 'channel', '5658'),
                $this->row(12, 2, 'post'),
                $this->row(13, 3, 'deleted'),
                $this->row(14, 4, 'deleted'),
            ]);

        $this->assertSame(
            [11, 12, 14],
            array_map(
                static fn (PublicationData $row): int => $row->id,
                $this->service()->formRowsOfGroup(self::GROUP, 'deleted', 14),
            ),
        );
    }

    public function testTheRowsOfAGroupComeInTheOrderOfTheirParts(): void
    {
        $this->_repository
            ->method('findGroupRows')
            ->willReturn([
                $this->row(21, 1, 'channel', '5658'),
                $this->row(22, 2, 'post'),
                $this->row(23, 3, 'draft'),
            ]);

        $this->assertSame(
            [21, 22, 23],
            array_map(
                static fn (PublicationData $row): int => $row->id,
                $this->service()->formRowsOfGroup(self::GROUP, 'draft', 23),
            ),
        );
    }

    public function testTheGroupOfAFormWithNoRowIsReadOnceAndNeverTwice(): void
    {
        $this->_repository
            ->expects($this->once())
            ->method('findGroupRows')
            ->with(self::GROUP)
            ->willReturn([$this->row(31, 1, 'post')]);

        $this->assertCount(1, $this->service()->formRowsOfGroup(self::GROUP, 'post', 31));
    }
}
