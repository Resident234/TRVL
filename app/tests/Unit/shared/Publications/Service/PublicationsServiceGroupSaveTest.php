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
 * Saving a form that holds the parts of one group. Three buttons answer for the
 * group, and each of them is a rule about blocks: «Опубликовать» gathers every
 * part into the publications block, giving a planned part the time of its own
 * queue row; «Сохранить» carries every part into the drafts block, and a part
 * the channel already holds leaves a debt there — the message gets deleted;
 * «Сохранить по местам» rewrites every part where it lies and asks the channel
 * to edit the message of a part that has already gone out. The place of every
 * part follows the form, and a part the form invented is written where the
 * button says.
 */
final class PublicationsServiceGroupSaveTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private const FIRST = 'Первая часть';
    private const SECOND = 'Вторая часть';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;
    private ScriptedChannelStub $_channel;

    /** The rows the three tables hold, keyed by the block and id of each. @var array<string, PublicationData> */
    private array $_rows = [];

    /** What the repository was asked to do, in the order the calls came in. @var array<string, array<int, mixed>> */
    private array $_calls = [];

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, $this->_channel);
    }

    /**
     * A row of the group as its table hands it over: the habitat says which
     * block it lives in, a Telegram id says it has already reached the channel.
     */
    private function row(
        int $id,
        int $order,
        string $habitat,
        string $at = '2026-10-04 08:00:00',
        bool $released = true,
        ?int $telegramId = null,
    ): PublicationData {
        return new PublicationData(
            $id,
            'Часть ' . $order,
            [],
            $telegramId,
            $habitat === 'draft' ? null : $at,
            '2026-10-04 07:00:00',
            '2026-10-04 07:30:00',
            null,
            partGroupId: self::GROUP,
            partOrder: $order,
            partReleased: $released,
            habitat: $habitat,
        );
    }

    /**
     * The row of a part the form holds, put into the block it lives in.
     */
    private function keep(PublicationData $row): PublicationData
    {
        $this->_rows[($row->habitat === 'channel' ? 'post' : (string)$row->habitat) . ':' . $row->id] = $row;

        return $row;
    }

    /**
     * The rows of the group as the repository reads them: a list in the order
     * the block hands them over.
     *
     * @return PublicationData[]
     */
    private function heldRows(): array
    {
        return array_values($this->_rows);
    }

    /**
     * The slot of one part as the form submits it: which row of which block it
     * was taken out of, or no row at all when the reader made the part himself.
     *
     * @return array{id: int|null, source: string}
     */
    private function slot(?int $id, string $source = 'post'): array
    {
        return ['id' => $id, 'source' => $source];
    }

    /**
     * @param array<int, array{id: int|null, source: string}> $slots
     * @param string[] $times
     * @param string[] $texts
     */
    private function save(
        array $slots,
        array $times = [],
        string $action = 'draft',
        ?array $texts = null,
        string $publishedAt = '2026-10-04 12:00:00',
    ): void {
        $texts = $texts ?? [self::FIRST, self::SECOND];
        $count = count($texts);

        $this->service()->saveGroupFromForm(
            $texts,
            array_fill(0, $count, []),
            array_fill(0, $count, new MessageEntities()),
            array_fill(0, $count, new LinkButtons()),
            array_fill(0, $count, ''),
            array_fill(0, $count, new MessageEntities()),
            $slots,
            $times,
            $publishedAt,
            $action,
            self::GROUP,
            'UTC',
        );
    }

    /**
     * @return array<int, mixed> the calls of one repository method
     */
    private function asked(string $method): array
    {
        return $this->_calls[$method] ?? [];
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_channel = new ScriptedChannelStub([]);
        $this->_rows = [];
        $this->_calls = [];

        $this->_repository
            ->method('findGroupRows')
            ->willReturnCallback(fn (): array => $this->heldRows());

        $this->_repository
            ->method('updatePost')
            ->willReturnCallback(
                function (
                    int $id,
                    string $text,
                    array $images,
                    string $publishedAt,
                    string $now,
                    MessageEntities $formatting,
                    LinkButtons $buttons,
                    string $title,
                    MessageEntities $titleFormatting,
                    ?int $partOrder = null,
                ): void {
                    $this->_calls['updatePost'][] = [$id, $text, $publishedAt, $partOrder];
                },
            );

        $this->_repository
            ->method('updateDraft')
            ->willReturnCallback(
                function (
                    int $id,
                    string $text,
                    array $images,
                    string $now,
                    MessageEntities $formatting,
                    LinkButtons $buttons,
                    string $title,
                    MessageEntities $titleFormatting,
                    ?int $partOrder = null,
                ): void {
                    $this->_calls['updateDraft'][] = [$id, $text, $partOrder];
                },
            );

        $this->_repository
            ->method('createPost')
            ->willReturnCallback(
                function (
                    string $text,
                    array $images,
                    string $publishedAt,
                    string $now,
                    MessageEntities $formatting,
                    LinkButtons $buttons,
                    string $title,
                    MessageEntities $titleFormatting,
                    string $groupId = '',
                    int $partOrder = 0,
                    bool $partReleased = true,
                ): int {
                    $this->_calls['createPost'][] = [$text, $publishedAt, $groupId, $partOrder, $partReleased];

                    return 400 + count($this->asked('createPost'));
                },
            );

        $this->_repository
            ->method('createDraft')
            ->willReturnCallback(
                function (
                    string $text,
                    array $images,
                    string $now,
                    MessageEntities $formatting,
                    LinkButtons $buttons,
                    string $title,
                    MessageEntities $titleFormatting,
                    string $groupId = '',
                    int $partOrder = 0,
                ): int {
                    $this->_calls['createDraft'][] = [$text, $groupId, $partOrder];

                    return 500 + count($this->asked('createDraft'));
                },
            );

        $this->_repository
            ->method('archiveEdited')
            ->willReturnCallback(
                function (PublicationData $record, string $now): void {
                    $this->_calls['archiveEdited'][] = $record;
                },
            );

        $deleters = [
            'deletePost' => 'post',
            'deleteDraft' => 'draft',
            'deleteDeleted' => 'deleted',
        ];

        foreach ($deleters as $method => $block) {
            $this->_repository
                ->method($method)
                ->willReturnCallback(
                    function (int $id) use ($method, $block): PublicationData {
                        $this->_calls[$method][] = $id;

                        $row = $this->_rows[$block . ':' . $id] ?? null;
                        unset($this->_rows[$block . ':' . $id]);

                        return $row ?? $this->row($id, 1, $block);
                    },
                );
        }

        foreach (
            [
                'insertPostWithHistory',
                'insertDraftWithHistory',
                'insertDeletedWithHistory',
                'insertChannelDeletionTicket',
            ] as $method
        ) {
            $this->_repository
                ->method($method)
                ->willReturnCallback(
                    function (PublicationData $record, string $now) use ($method): int {
                        $this->_calls[$method][] = $record;

                        return 600 + count($this->asked($method));
                    },
                );
        }
    }

    public function testSavingEachPartInPlaceRewritesEachPartWhereItLies(): void
    {
        $this->keep($this->row(11, 1, 'post', '2026-10-04 08:00:00'));
        $this->keep($this->row(12, 2, 'post', '2026-10-04 09:00:00'));

        // The two parts stand swapped in the form, and «Сохранить по местам» does
        // not read the queue rows: each part keeps the time its own row was
        // written with.
        $this->save(
            [$this->slot(12), $this->slot(11)],
            ['2026-10-05 10:00:00', '2026-10-05 11:00:00'],
            'home',
        );

        $this->assertSame(
            [
                [12, self::FIRST, '2026-10-04 09:00:00', 1],
                [11, self::SECOND, '2026-10-04 08:00:00', 2],
            ],
            $this->asked('updatePost'),
        );
        $this->assertSame([], $this->asked('deletePost'));
        $this->assertSame([], $this->asked('insertDraftWithHistory'));
    }

    public function testSavingAGroupCarriesItsPlannedPartsIntoDrafts(): void
    {
        $this->keep($this->row(11, 1, 'post', '2026-10-04 08:00:00'));
        $this->keep($this->row(12, 2, 'post', '2026-10-04 09:00:00'));

        $this->save(
            [$this->slot(11), $this->slot(12)],
            ['2026-10-05 10:00:00', '2026-10-05 11:00:00'],
        );

        $this->assertSame([11, 12], $this->asked('deletePost'));
        $this->assertSame(
            [],
            $this->asked('updatePost'),
            '«Сохранить» leaves no part standing in the publications block.',
        );

        $carried = $this->asked('insertDraftWithHistory');
        $this->assertCount(2, $carried);
        $this->assertSame(self::FIRST, $carried[0]->text, 'The draft is the text the form brought.');
        $this->assertSame(self::GROUP, $carried[0]->partGroupId);
        $this->assertSame(1, $carried[0]->partOrder);
        $this->assertSame(self::SECOND, $carried[1]->text);
        $this->assertSame(2, $carried[1]->partOrder);
        $this->assertSame(
            [],
            $this->asked('insertChannelDeletionTicket'),
            'A part the channel has never held owes it nothing.',
        );
    }

    public function testSavingAGroupCarriesASentPartIntoDraftsAndOwesTheChannel(): void
    {
        $this->keep($this->row(11, 1, 'channel', '2026-10-04 08:00:00', true, 5658));
        $this->keep($this->row(12, 2, 'draft'));

        $this->save([$this->slot(11), $this->slot(12, 'draft')]);

        $this->assertSame([11], $this->asked('deletePost'));

        $drafts = $this->asked('insertDraftWithHistory');
        $this->assertCount(1, $drafts);
        $this->assertSame(self::FIRST, $drafts[0]->text);
        $this->assertNull($drafts[0]->telegramId, 'A draft holds no message of the channel.');

        $debts = $this->asked('insertChannelDeletionTicket');
        $this->assertCount(1, $debts, 'The message the part is taken away of is owed to the channel.');
        $this->assertSame(5658, $debts[0]->telegramId);
        $this->assertSame('Часть 1', $debts[0]->text, 'The debt describes the message, not the draft behind it.');
        $this->assertSame(
            [],
            $this->asked('insertDeletedWithHistory'),
            'The debt is not a part of the group: the draft of the same place is.',
        );
        $this->assertSame(
            [],
            $this->asked('archiveEdited'),
            'The channel is asked to delete the message, not to edit it.',
        );
        $this->assertSame([[12, self::SECOND, 2]], $this->asked('updateDraft'));
    }

    public function testSavingAGroupRestoresADeletedPartIntoDrafts(): void
    {
        $this->keep($this->row(11, 1, 'deleted'));
        $this->keep($this->row(12, 2, 'post', '2026-10-04 09:00:00'));

        $this->save([$this->slot(11, 'deleted'), $this->slot(12)]);

        $this->assertSame([11], $this->asked('deleteDeleted'));
        $this->assertSame([12], $this->asked('deletePost'));

        $drafts = $this->asked('insertDraftWithHistory');
        $this->assertCount(2, $drafts);
        $this->assertSame(self::FIRST, $drafts[0]->text);
        $this->assertSame(1, $drafts[0]->partOrder);
        $this->assertSame(self::SECOND, $drafts[1]->text);
        $this->assertSame(2, $drafts[1]->partOrder);
    }

    public function testSavingEachPartInPlaceSendsASentPartToTheChannelEdit(): void
    {
        $this->keep($this->row(11, 1, 'channel', '2026-10-04 08:00:00', true, 5658));

        $this->save([$this->slot(11)], [], 'home', [self::FIRST]);

        $archived = $this->asked('archiveEdited');
        $this->assertCount(1, $archived, 'A part rewritten in place is the message the channel is told to show.');
        $this->assertSame(self::FIRST, $archived[0]->text);
        $this->assertSame(5658, $archived[0]->telegramId);
        $this->assertSame(
            [[11, self::FIRST, '2026-10-04 08:00:00', 1]],
            $this->asked('updatePost'),
            'The row keeps standing in the publications block with the time it went out at.',
        );
        $this->assertSame([], $this->asked('deletePost'), 'The part does not leave its block.');
        $this->assertSame([], $this->asked('insertChannelDeletionTicket'));
    }

    public function testSavingEachPartInPlaceKeepsADraftedPartInDrafts(): void
    {
        $this->keep($this->row(11, 1, 'draft'));

        $this->save([$this->slot(11, 'draft')], [], 'home', [self::FIRST]);

        $this->assertSame([[11, self::FIRST, 1]], $this->asked('updateDraft'));
        $this->assertSame([], $this->asked('deleteDraft'));
        $this->assertSame([], $this->asked('insertPostWithHistory'));
    }

    public function testSavingEachPartInPlaceRestoresADeletedPartIntoDrafts(): void
    {
        $this->keep($this->row(11, 1, 'deleted'));

        $this->save([$this->slot(11, 'deleted')], [], 'home', [self::FIRST]);

        $this->assertSame([11], $this->asked('deleteDeleted'));

        $drafts = $this->asked('insertDraftWithHistory');
        $this->assertCount(1, $drafts);
        $this->assertSame(self::FIRST, $drafts[0]->text);
        $this->assertSame(self::GROUP, $drafts[0]->partGroupId);
        $this->assertSame(1, $drafts[0]->partOrder);
    }

    public function testSavingEachPartInPlaceWritesAPartWithoutAPlaceAsADraft(): void
    {
        $this->keep($this->row(11, 1, 'post', '2026-10-04 08:00:00'));

        $this->save([$this->slot(11), $this->slot(null)], [], 'home', [self::FIRST, self::SECOND]);

        $this->assertSame([[11, self::FIRST, '2026-10-04 08:00:00', 1]], $this->asked('updatePost'));
        $this->assertSame(
            [[self::SECOND, self::GROUP, 2]],
            $this->asked('createDraft'),
            'A part the form invented holds no place of its own, so it starts in the drafts block.',
        );
        $this->assertSame([], $this->asked('createPost'));
    }

    public function testPublishingAGroupGivesEachPlannedPartTheTimeOfItsOwnRow(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'post'));

        $this->save(
            [$this->slot(11), $this->slot(12)],
            ['2026-10-05 10:00:00', '2026-10-05 11:00:00'],
            'publish',
        );

        $this->assertSame(
            [
                [11, self::FIRST, '2026-10-05 10:00:00', 1],
                [12, self::SECOND, '2026-10-05 11:00:00', 2],
            ],
            $this->asked('updatePost'),
        );
    }

    public function testAPartWithoutItsOwnTimeTakesTheSequenceOfTheGroup(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'post'));

        $this->save([$this->slot(11), $this->slot(12)], [], 'publish');

        $this->assertSame(
            [
                [11, self::FIRST, '2026-10-04 12:00:00', 1],
                [12, self::SECOND, '2026-10-04 12:01:00', 2],
            ],
            $this->asked('updatePost'),
        );
    }

    public function testAPartThatReachedTheChannelGoesToTheChannelEditWithItsNewText(): void
    {
        $this->keep($this->row(11, 1, 'channel', '2026-10-04 08:00:00', true, 5658));

        $this->save([$this->slot(11)], [], 'publish', ['Новый текст']);

        $archived = $this->asked('archiveEdited');
        $this->assertCount(1, $archived, 'The channel is told to edit the message of this part.');
        $this->assertSame('Новый текст', $archived[0]->text);
        $this->assertSame(5658, $archived[0]->telegramId);
        $this->assertSame(
            [[11, 'Новый текст', '2026-10-04 08:00:00', 1]],
            $this->asked('updatePost'),
            'The row stays in the publications block as the published part it is.',
        );
        $this->assertSame([], $this->asked('deletePost'));
    }

    public function testADraftPartPublishedWithTheGroupMovesIntoThePublicationsBlock(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'draft', released: false));

        $this->save([$this->slot(11), $this->slot(12, 'draft')], [], 'publish');

        $carried = $this->asked('insertPostWithHistory');
        $this->assertCount(1, $carried);
        $this->assertSame(self::SECOND, $carried[0]->text);
        $this->assertSame(2, $carried[0]->partOrder);
        $this->assertSame(self::GROUP, $carried[0]->partGroupId);
        $this->assertSame('2026-10-04 12:01:00', $carried[0]->publishedAt);
        $this->assertSame([12], $this->asked('deleteDraft'));
    }

    public function testADraftPartCarriedIntoTheQueueBehindAHeldPartStaysHeldBack(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'post', released: false));
        $this->keep($this->row(13, 3, 'draft'));

        $this->save(
            [$this->slot(11), $this->slot(12), $this->slot(13, 'draft')],
            [],
            'publish',
            [self::FIRST, self::SECOND, 'Третья часть'],
        );

        $carried = $this->asked('insertPostWithHistory');
        $this->assertCount(1, $carried);
        $this->assertFalse(
            $carried[0]->partReleased,
            'A drafted part holds no place of its own in the queue: behind a part that '
            . 'still waits it enters the block waiting as well.',
        );
    }

    public function testADraftPartCarriedIntoTheQueueBehindReleasedPartsEntersTheQueue(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'draft'));

        $this->save(
            [$this->slot(11), $this->slot(12, 'draft')],
            [],
            'publish',
            [self::FIRST, self::SECOND],
        );

        $carried = $this->asked('insertPostWithHistory');
        $this->assertCount(1, $carried);
        $this->assertTrue(
            $carried[0]->partReleased,
            'With every part above it already open to the queue the drafted part goes in too.',
        );
    }

    public function testARestoredPartCarriedIntoTheQueueBehindAHeldPartStaysHeldBack(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'post', released: false));
        $this->keep($this->row(13, 3, 'deleted'));

        $this->save(
            [$this->slot(11), $this->slot(12), $this->slot(13, 'deleted')],
            [],
            'publish',
            [self::FIRST, self::SECOND, 'Третья часть'],
        );

        $carried = $this->asked('insertPostWithHistory');
        $this->assertCount(1, $carried);
        $this->assertFalse(
            $carried[0]->partReleased,
            'A part restored from the deleted block joins the queue behind the part above it.',
        );
    }

    public function testANewPartSavedWithTheGroupIsWrittenAsItsDraft(): void
    {
        $this->keep($this->row(11, 1, 'post'));

        $this->save([$this->slot(11), $this->slot(null)], []);

        $this->assertSame([[self::SECOND, self::GROUP, 2]], $this->asked('createDraft'));
    }

    public function testANewPartWaitsInTheQueueWhenThePartAboveItWaits(): void
    {
        $this->keep($this->row(11, 1, 'post', released: false));

        $this->save([$this->slot(11), $this->slot(null)], ['2026-10-05 10:00:00', '2026-10-05 12:00:00'], 'publish');

        $this->assertSame(
            [[self::SECOND, '2026-10-05 12:00:00', self::GROUP, 2, false]],
            $this->asked('createPost'),
            'A part the form invented cannot slip ahead of the part above it.',
        );
    }

    public function testANewPartStandsInTheQueueWhenThePartAboveItStandsThere(): void
    {
        $this->keep($this->row(11, 1, 'post'));

        $this->save([$this->slot(11), $this->slot(null)], ['2026-10-05 10:00:00', '2026-10-05 12:00:00'], 'publish');

        $this->assertSame([[self::SECOND, '2026-10-05 12:00:00', self::GROUP, 2, true]], $this->asked('createPost'));
    }

    public function testAPartTheFormDroppedLeavesTheGroupForTheDeletedBlock(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'post'));
        $this->keep($this->row(13, 3, 'deleted'));

        $this->save([$this->slot(11)], [], 'draft', [self::FIRST]);

        $this->assertSame(
            [11, 12],
            $this->asked('deletePost'),
            'The part the form claims leaves the publications block with the same button, '
            . 'but only the dropped one leaves the group.',
        );
        $left = $this->asked('insertDeletedWithHistory');
        $this->assertCount(1, $left);
        $this->assertSame(12, $left[0]->id);
        $this->assertSame(
            [],
            $this->asked('deleteDeleted'),
            'A part that already rests in the deleted block was never in the form and stays there.',
        );
    }

    public function testARestoredDeletedPartTakesItsPlaceInTheGroup(): void
    {
        $this->keep($this->row(11, 1, 'post'));
        $this->keep($this->row(12, 2, 'post'));
        $this->keep($this->row(13, 3, 'deleted'));

        $this->save(
            [$this->slot(11), $this->slot(12), $this->slot(13, 'deleted')],
            [],
            'draft',
            [self::FIRST, self::SECOND, 'Третья часть'],
        );

        $carried = $this->asked('insertDraftWithHistory');
        $this->assertCount(3, $carried, '«Сохранить» carries the whole group into the drafts block.');
        $this->assertSame(
            [1, 2, 3],
            array_map(
                static fn (PublicationData $draft): int => (int)$draft->partOrder,
                $carried,
            ),
            'The part taken back from the deleted block stands last, where the form holds it.',
        );
        $this->assertSame('Третья часть', $carried[2]->text);
        $this->assertSame(self::GROUP, $carried[2]->partGroupId);
    }

    public function testASlotWhoseRowHasAlreadyMovedRefusesTheSave(): void
    {
        $this->keep($this->row(11, 1, 'post'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Часть публикации уже перемещена. Обновите форму и отправьте ещё раз.');

        $this->save([$this->slot(99)], [], 'draft', [self::FIRST]);
    }

    public function testAPartWithoutTextOrAlbumRefusesTheSave(): void
    {
        $this->keep($this->row(11, 1, 'post'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Публикации нужны текст или хотя бы одно изображение.');

        $this->save([$this->slot(11)], [], 'draft', ['']);
    }
}
