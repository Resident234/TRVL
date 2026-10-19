<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Publications\Contract\PublicationRepositoryInterface;
use app\shared\Publications\Dto\PublicationData;
use app\shared\Publications\Service\PublicationsService;
use Codeception\Test\Unit;
use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use InvalidArgumentException;

/**
 * A publication split into parts stays one publication while its parts move
 * between the three blocks: the group and the place inside it travel with the
 * row, and every save of several parts writes them from the start.
 */
final class PublicationsServicePartGroupTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private const FIRST = 'Первая часть';
    private const SECOND = 'Вторая часть';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ScriptedChannelStub $_channel;

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, $this->_channel);
    }

    /**
     * A part of a group as one of the three tables hands it over: the first
     * part has reached the queue, a lower one is still held back.
     */
    private function part(int $id, string $text, int $order, bool $released, ?int $telegramId = null): PublicationData
    {
        return new PublicationData(
            $id,
            $text,
            [],
            $telegramId,
            '2026-10-04 08:50:00',
            '2026-10-04 08:00:00',
            '2026-10-04 08:00:00',
            partGroupId: self::GROUP,
            partOrder: $order,
            partReleased: $released,
        );
    }

    /**
     * The two parts of a split publication as the form hands them over: only
     * the texts, since the rest the service fills in from its own defaults.
     *
     * @return string[]
     */
    private function texts(): array
    {
        return [self::FIRST, self::SECOND];
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_channel = new ScriptedChannelStub([]);
    }

    public function testADraftMadeOfSeveralPartsIsStoredAsOneGroup(): void
    {
        $this->_repository->method('groupExists')->willReturn(false);

        $written = [];
        $this->_repository
            ->expects($this->once())
            ->method('createDrafts')
            ->willReturnCallback(
                static function (array $rows, string $now, string $groupId = '') use (&$written): int {
                    $written[] = $groupId;

                    return 1;
                },
            );

        $this->service()->saveParts($this->texts(), [], [], [], [], '', 'draft', null, 'UTC');

        $this->assertCount(1, $written, 'The parts of one draft publication are written in one call.');
        $this->assertSame(
            1,
            preg_match('/^[a-f0-9]{32}$/', $written[0]),
            'A draft made of parts is a group from the moment it is written.',
        );
    }

    public function testPlainPostsMadeOfSeveralPartsAreStoredAsOneGroup(): void
    {
        $this->_repository->method('groupExists')->willReturn(false);

        $written = [];
        $this->_repository
            ->expects($this->once())
            ->method('createPosts')
            ->willReturnCallback(
                static function (array $rows, string $now, string $groupId = '') use (&$written): int {
                    $written[] = $groupId;

                    return 1;
                },
            );

        $this->service()->saveParts($this->texts(), [], [], [], [], '2026-10-04 08:50:00', 'publish', null, 'UTC');

        $this->assertSame(
            1,
            preg_match('/^[a-f0-9]{32}$/', $written[0] ?? ''),
            'Parts that all go out on their own still belong to one publication.',
        );
    }

    public function testAStagedGroupTakesTheIdTheFormCarried(): void
    {
        $this->_repository->method('groupExists')->willReturn(false);

        $groups = [];
        $this->_repository
            ->expects($this->once())
            ->method('createStagedGroup')
            ->willReturnCallback(
                static function (array $rows, string $groupId, string $now) use (&$groups): int {
                    $groups[] = $groupId;

                    return 1;
                },
            );

        $this->service()->saveParts(
            $this->texts(),
            [],
            [],
            [],
            [],
            '2026-10-04 08:50:00',
            'publish',
            null,
            'UTC',
            true,
            ['2026-10-04 08:50:00', '2026-10-04 09:50:00'],
            self::GROUP,
        );

        $this->assertSame(
            [self::GROUP],
            $groups,
            'The id the form made up binds the parts, so a second save of the same form finds them again.',
        );
    }

    public function testASavedGroupIsNotWrittenTwiceFromAStaleForm(): void
    {
        $this->_repository->method('groupExists')->willReturn(true);

        $this->_repository
            ->expects($this->never())
            ->method('createDrafts');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Эта группа частей уже сохранена. Обновите форму и отправьте ещё раз.');

        $this->service()->saveParts(
            $this->texts(),
            [],
            [],
            [],
            [],
            '',
            'draft',
            null,
            'UTC',
            false,
            [],
            self::GROUP,
        );
    }

    public function testMovingAPartToDraftsKeepsItsGroupAndItsWait(): void
    {
        $second = $this->part(2, self::SECOND, 2, false);

        $this->_repository->method('deletePost')->willReturn($second);

        $carried = null;
        $this->_repository
            ->expects($this->once())
            ->method('insertDraftWithHistory')
            ->willReturnCallback(
                static function (PublicationData $record, string $now) use (&$carried): int {
                    $carried = $record;

                    return 7;
                },
            );

        $this->service()->movePostToDraft(2);

        $this->assertNotNull($carried);
        $this->assertSame(self::GROUP, $carried->partGroupId, 'A part that rests in drafts belongs to its group.');
        $this->assertSame(2, $carried->partOrder);
        $this->assertFalse(
            $carried->partReleased,
            'A part held back of the queue must still be held back when it comes out of drafts.',
        );
    }

    public function testPublishingADraftPartKeepsItsGroup(): void
    {
        $first = $this->part(1, self::FIRST, 1, true);

        $this->_repository->method('deleteDraft')->willReturn($first);

        $carried = null;
        $this->_repository
            ->expects($this->once())
            ->method('insertPostWithHistory')
            ->willReturnCallback(
                static function (PublicationData $record, string $now) use (&$carried): int {
                    $carried = $record;

                    return 9;
                },
            );

        $this->service()->publishDraft(1);

        $this->assertSame(self::GROUP, $carried->partGroupId);
        $this->assertSame(1, $carried->partOrder);
        $this->assertTrue($carried->partReleased, 'The first part of a group enters the queue at once.');
    }

    public function testSchedulingADeletedPartKeepsItsGroupAndItsWait(): void
    {
        $second = $this->part(2, self::SECOND, 2, false);

        $this->_repository->method('deleteDeleted')->willReturn($second);

        $carried = null;
        $this->_repository
            ->expects($this->once())
            ->method('insertPostWithHistory')
            ->willReturnCallback(
                static function (PublicationData $record, string $now) use (&$carried): int {
                    $carried = $record;

                    return 11;
                },
            );

        $this->service()->scheduleDeleted(2, '2026-10-04 09:30:00', 'UTC');

        $this->assertSame(self::GROUP, $carried->partGroupId);
        $this->assertSame(2, $carried->partOrder);
        $this->assertFalse(
            $carried->partReleased,
            'A restored part must not slip into the queue ahead of the part above it.',
        );
    }

    public function testRestoringAPartFromTheFormKeepsItsGroup(): void
    {
        $second = $this->part(2, self::SECOND, 2, false);

        $this->_repository->method('findDeleted')->willReturn($second);
        $this->_repository->method('deleteDeleted')->willReturn($second);

        $carried = null;
        $this->_repository
            ->expects($this->once())
            ->method('insertPostWithHistory')
            ->willReturnCallback(
                static function (PublicationData $record, string $now) use (&$carried): int {
                    $carried = $record;

                    return 12;
                },
            );

        $this->service()->saveFromForm(
            'Вторая часть переписана',
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            '2026-10-04 09:30:00',
            'deleted',
            2,
            'publish',
            'UTC',
        );

        $this->assertSame(self::GROUP, $carried->partGroupId, 'The form never unties a part from its group.');
        $this->assertSame(2, $carried->partOrder);
        $this->assertFalse($carried->partReleased);
        $this->assertSame('Вторая часть переписана', $carried->text);
    }

    public function testSavingAPostPartFromTheFormKeepsItsGroup(): void
    {
        $first = $this->part(1, self::FIRST, 1, true);

        $this->_repository->method('findPost')->willReturn($first);
        $this->_repository->method('deletePost')->willReturn($first);

        $carried = null;
        $this->_repository
            ->expects($this->once())
            ->method('insertDraftWithHistory')
            ->willReturnCallback(
                static function (PublicationData $record, string $now) use (&$carried): int {
                    $carried = $record;

                    return 13;
                },
            );

        $this->service()->saveFromForm(
            'Первая часть переписана',
            [],
            new MessageEntities(),
            new LinkButtons(),
            '',
            '2026-10-04 08:50:00',
            'post',
            1,
            'draft',
            'UTC',
        );

        $this->assertSame(self::GROUP, $carried->partGroupId);
        $this->assertSame(1, $carried->partOrder);
    }
}
