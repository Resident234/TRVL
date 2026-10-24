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
use stdClass;

/**
 * The marks a reader sets over the heading of a part go on the same journey its
 * text does. Every write path of the service hands the list to the row it saves,
 * a heading the service trims takes the list that describes it along, a heading
 * the reader erased loses it, and a span that does not fit the heading is refused
 * the way a span of the body is. A move between the blocks carries the marks of
 * the heading with the rest of the content, and never mixes them with the spans
 * of the body the heading stands over — which is why the two lists in these tests
 * are deliberately unlike each other.
 */
final class PublicationsServiceTitleFormattingTest extends Unit
{
    private const GROUP = 'abababababababababababababababab';

    private const HEADING = 'Заголовок';
    private const BODY = 'Текст публикации';

    private PublicationRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject $_repository;

    /**
     * What each watched repository method was handed, in the order the calls came
     * in. The places of the list are the places of the method's own signature.
     *
     * @var array<string, array<int, array<int, mixed>>>
     */
    private array $_calls = [];

    private function service(): PublicationsService
    {
        return new PublicationsService($this->_repository, null, new stdClass());
    }

    /**
     * One span of one list, so a test names the mark it cares about and nothing else.
     */
    private function marks(string $type, int $offset, int $length): MessageEntities
    {
        return MessageEntities::fromArray([['type' => $type, 'offset' => $offset, 'length' => $length]]);
    }

    /**
     * The marks of the heading these parts carry: an underline over its first four
     * characters, which no span of the body below repeats.
     */
    private function headingMarks(): MessageEntities
    {
        return $this->marks('underline', 0, 4);
    }

    private function bodyMarks(): MessageEntities
    {
        return $this->marks('italic', 3, 5);
    }

    /**
     * A write method of the repository watched instead of performed: nothing is
     * stored here, only the arguments the service chose to hand over are kept.
     */
    private function watch(string $method): void
    {
        $this->_repository
            ->method($method)
            ->willReturnCallback(
                function (...$args) use ($method): int {
                    $this->_calls[$method][] = $args;

                    return 100 + count($this->_calls[$method]);
                },
            );
    }

    /**
     * @return array<int, mixed> the arguments of the single call a watched method took
     */
    private function asked(string $method): array
    {
        $calls = $this->_calls[$method] ?? [];
        $this->assertCount(1, $calls, "The service was to write the {$method} row once.");

        return $calls[0];
    }

    /**
     * A row of the group as its block hands it over. Its own heading marks are
     * unlike the ones the form is bringing, so a move that forgets either of them
     * cannot pass for one that carried both.
     */
    private function row(
        int $id,
        int $order,
        string $habitat,
        ?int $telegramId = null,
    ): PublicationData {
        return new PublicationData(
            $id,
            'Часть ' . $order,
            [],
            $telegramId,
            '2026-10-04 08:00:00',
            '2026-10-04 07:00:00',
            '2026-10-04 07:30:00',
            null,
            $this->marks('bold', 0, 5),
            new LinkButtons(),
            'Записанный заголовок',
            partGroupId: self::GROUP,
            partOrder: $order,
            habitat: $habitat,
            titleFormatting: $this->marks('italic', 6, 4),
        );
    }

    /**
     * The slot of one part of the form: which row of which block it came out of.
     *
     * @return array{id: int|null, source: string}
     */
    private function slot(?int $id, string $source = 'post'): array
    {
        return ['id' => $id, 'source' => $source];
    }

    protected function _before(): void
    {
        parent::_before();

        $this->_repository = $this->createMock(PublicationRepositoryInterface::class);
        $this->_calls = [];
    }

    public function testADraftOfOnePartIsWrittenWithTheMarksOfItsHeading(): void
    {
        $this->watch('createDraft');

        $this->service()->saveDraft(
            self::BODY,
            [],
            $this->bodyMarks(),
            new LinkButtons(),
            self::HEADING,
            $this->headingMarks(),
        );

        $row = $this->asked('createDraft');
        $this->assertSame(self::BODY, $row[0], 'The text of the part keeps its own place of the row.');
        $this->assertSame($this->bodyMarks()->toArray(), $row[3]->toArray(), 'The body keeps its own list.');
        $this->assertSame(self::HEADING, $row[5]);
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $row[6]->toArray(),
            'The marks of the heading are written beside the heading, not inside the body.',
        );
    }

    public function testAScheduledPostIsWrittenWithTheMarksOfItsHeading(): void
    {
        $this->watch('createPost');

        $this->service()->schedulePost(
            self::BODY,
            [],
            $this->bodyMarks(),
            new LinkButtons(),
            self::HEADING,
            $this->headingMarks(),
            '2026-10-04 08:50:00',
            null,
            'UTC',
        );

        $row = $this->asked('createPost');
        $this->assertSame($this->bodyMarks()->toArray(), $row[4]->toArray());
        $this->assertSame(self::HEADING, $row[6]);
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $row[7]->toArray(),
            'A part that goes to the queue carries the marks its heading wears.',
        );
    }

    public function testAPartSavedFromItsOwnCornerIsWrittenWithTheMarksOfItsHeading(): void
    {
        $this->_repository->method('findGroupRows')->willReturn([]);
        $this->watch('createDraft');

        $this->service()->savePartAsDraft(
            self::BODY,
            [],
            $this->bodyMarks(),
            new LinkButtons(),
            self::HEADING,
            $this->headingMarks(),
            '',
        );

        $row = $this->asked('createDraft');
        $this->assertSame($this->headingMarks()->toArray(), $row[6]->toArray());
        $this->assertSame($this->bodyMarks()->toArray(), $row[3]->toArray());
    }

    public function testAnEditedPostRewritesItsRowWithTheMarksOfItsHeading(): void
    {
        $this->_repository->method('findPost')->willReturn($this->row(11, 1, 'post'));
        $this->watch('updatePost');

        $this->service()->saveFromForm(
            self::BODY,
            [],
            $this->bodyMarks(),
            new LinkButtons(),
            self::HEADING,
            $this->headingMarks(),
            '2026-10-04 08:50:00',
            'post',
            11,
            'publish',
            'UTC',
        );

        $row = $this->asked('updatePost');
        $this->assertSame($this->bodyMarks()->toArray(), $row[5]->toArray());
        $this->assertSame(self::HEADING, $row[7]);
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $row[8]->toArray(),
            'A part edited in place rewrites the marks of its heading with the rest of it.',
        );
    }

    public function testTheMarksOfAnEditedHeadingFollowTheHeadingThatWasTrimmed(): void
    {
        $this->_repository->method('findPost')->willReturn($this->row(11, 1, 'post'));
        $this->watch('updatePost');

        $this->service()->saveFromForm(
            self::BODY,
            [],
            new MessageEntities(),
            new LinkButtons(),
            '  ' . self::HEADING,
            $this->marks('underline', 2, 4),
            '2026-10-04 08:50:00',
            'post',
            11,
            'publish',
            'UTC',
        );

        $row = $this->asked('updatePost');
        $this->assertSame(self::HEADING, $row[7], 'The heading is stored without the whitespace around it.');
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $row[8]->toArray(),
            'The marks of a heading count from its first character, so the whitespace the '
            . 'heading loses moves them along with it.',
        );
    }

    public function testASentPartRewrittenFromTheFormCarriesItsHeadingMarksIntoTheArchive(): void
    {
        $this->_repository->method('findPost')->willReturn($this->row(11, 1, 'post', 5658));
        $this->watch('archiveEdited');
        $this->watch('updatePost');

        $this->service()->saveFromForm(
            self::BODY,
            [],
            $this->bodyMarks(),
            new LinkButtons(),
            self::HEADING,
            $this->headingMarks(),
            '2026-10-04 08:50:00',
            'post',
            11,
            'publish',
            'UTC',
        );

        $archived = $this->asked('archiveEdited')[0];
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $archived->titleFormatting->toArray(),
            'The message the channel is told to show wears the marks the form brought.',
        );
        $this->assertSame($this->bodyMarks()->toArray(), $archived->formatting->toArray());
    }

    public function testAHeadingOfItsOwnCarriesItsMarksOutOfTheChannelIntoADraft(): void
    {
        $this->_repository->method('findPost')->willReturn($this->row(11, 1, 'post', 5658));
        $this->_repository->method('deletePost')->willReturn($this->row(11, 1, 'post', 5658));
        $this->watch('insertDraftWithHistory');

        $this->service()->saveFromForm(
            self::BODY,
            [],
            $this->bodyMarks(),
            new LinkButtons(),
            self::HEADING,
            $this->headingMarks(),
            '',
            'post',
            11,
            'draft',
            'UTC',
        );

        $draft = $this->asked('insertDraftWithHistory')[0];
        $this->assertSame(self::HEADING, $draft->title);
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $draft->titleFormatting->toArray(),
            'The draft the part becomes wears the marks its heading was given.',
        );
        $this->assertSame($this->bodyMarks()->toArray(), $draft->formatting->toArray());
    }

    public function testAHeadingTrimmedForANewPartTakesItsMarksWithIt(): void
    {
        $this->watch('createDraft');

        $this->service()->saveParts(
            [self::BODY],
            [],
            [],
            [],
            ['  ' . self::HEADING . ' '],
            [$this->marks('underline', 2, 4)],
            '',
            'draft',
            null,
            'UTC',
        );

        $row = $this->asked('createDraft');
        $this->assertSame(self::HEADING, $row[5]);
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $row[6]->toArray(),
            'A part written from the form is saved with its heading trimmed, and the marks move with it.',
        );
    }

    public function testTheMarksOfAHeadingTheReaderErasedGoWithIt(): void
    {
        $this->watch('createDraft');

        $this->service()->saveParts(
            [self::BODY],
            [],
            [],
            [],
            ['   '],
            [$this->marks('bold', 0, 3)],
            '',
            'draft',
            null,
            'UTC',
        );

        $row = $this->asked('createDraft');
        $this->assertSame('', $row[5], 'A heading of nothing but whitespace is no heading.');
        $this->assertSame(
            [],
            $row[6]->toArray(),
            'Marks over a heading that is gone leave with it instead of refusing the save.',
        );
    }

    public function testEachPartOfADraftGroupIsWrittenWithItsOwnHeadingMarks(): void
    {
        $this->_repository->method('groupExists')->willReturn(false);
        $this->watch('createDrafts');

        $this->service()->saveParts(
            [self::BODY, 'Вторая часть'],
            [],
            [],
            [],
            [self::HEADING, 'Второй заголовок'],
            [$this->headingMarks(), $this->marks('bold', 7, 3)],
            '',
            'draft',
            null,
            'UTC',
        );

        $rows = $this->asked('createDrafts')[0];
        $this->assertSame(
            [
                $this->headingMarks()->toArray(),
                $this->marks('bold', 7, 3)->toArray(),
            ],
            [
                $rows[0]['titleFormatting']->toArray(),
                $rows[1]['titleFormatting']->toArray(),
            ],
            'Every part of the group is written with the marks of its own heading.',
        );
        $this->assertSame('Второй заголовок', $rows[1]['title']);
    }

    public function testEachPartOfAPublishedGroupIsWrittenWithItsOwnHeadingMarks(): void
    {
        $this->_repository->method('groupExists')->willReturn(false);
        $this->watch('createPosts');

        $this->service()->saveParts(
            [self::BODY, 'Вторая часть'],
            [],
            [],
            [],
            [self::HEADING, 'Второй заголовок'],
            [$this->headingMarks(), $this->marks('bold', 7, 3)],
            '2026-10-04 08:50:00',
            'publish',
            null,
            'UTC',
        );

        $rows = $this->asked('createPosts')[0];
        $this->assertSame(
            [
                $this->headingMarks()->toArray(),
                $this->marks('bold', 7, 3)->toArray(),
            ],
            [
                $rows[0]['titleFormatting']->toArray(),
                $rows[1]['titleFormatting']->toArray(),
            ],
        );
    }

    public function testEachPartOfAGroupCarriesItsHeadingMarksBackToItsOwnRow(): void
    {
        $this->_repository
            ->method('findGroupRows')
            ->willReturn([$this->row(11, 1, 'post'), $this->row(12, 2, 'draft')]);
        $this->watch('updatePost');
        $this->watch('updateDraft');

        $this->service()->saveGroupFromForm(
            [self::BODY, 'Вторая часть'],
            [],
            [],
            [],
            [self::HEADING, 'Второй заголовок'],
            [$this->headingMarks(), $this->marks('bold', 7, 3)],
            [$this->slot(11), $this->slot(12, 'draft')],
            [],
            '2026-10-04 08:50:00',
            'home',
            self::GROUP,
            'UTC',
        );

        $post = $this->asked('updatePost');
        $this->assertSame(self::HEADING, $post[7]);
        $this->assertSame($this->headingMarks()->toArray(), $post[8]->toArray());

        $draft = $this->asked('updateDraft');
        $this->assertSame('Второй заголовок', $draft[6]);
        $this->assertSame(
            $this->marks('bold', 7, 3)->toArray(),
            $draft[7]->toArray(),
            'A drafted part takes the marks the form holds for it, not the ones of its neighbour.',
        );
    }

    public function testAPartTheFormInventedIsWrittenIntoTheGroupWithItsHeadingMarks(): void
    {
        $this->_repository->method('findGroupRows')->willReturn([]);
        $this->watch('createDraft');

        $this->service()->saveGroupFromForm(
            [self::BODY],
            [],
            [],
            [],
            [self::HEADING],
            [$this->headingMarks()],
            [$this->slot(null)],
            [],
            '2026-10-04 08:50:00',
            'draft',
            self::GROUP,
            'UTC',
        );

        $row = $this->asked('createDraft');
        $this->assertSame(self::GROUP, $row[7]);
        $this->assertSame(1, $row[8]);
        $this->assertSame(
            $this->headingMarks()->toArray(),
            $row[6]->toArray(),
            'A part without a row of its own starts with the marks its heading wears.',
        );
    }

    public function testADraftedPartCarriedIntoTheQueueKeepsTheMarksOfItsHeading(): void
    {
        $this->_repository
            ->method('findGroupRows')
            ->willReturn([$this->row(12, 2, 'draft')]);
        $this->_repository->method('deleteDraft')->willReturn($this->row(12, 2, 'draft'));
        $this->watch('insertPostWithHistory');

        $this->service()->saveGroupFromForm(
            [self::BODY],
            [],
            [$this->bodyMarks()],
            [],
            [self::HEADING],
            [$this->headingMarks()],
            [$this->slot(12, 'draft')],
            [],
            '2026-10-04 08:50:00',
            'publish',
            self::GROUP,
            'UTC',
        );

        $post = $this->asked('insertPostWithHistory')[0];
        $this->assertSame($this->headingMarks()->toArray(), $post->titleFormatting->toArray());
        $this->assertSame(
            $this->bodyMarks()->toArray(),
            $post->formatting->toArray(),
            'And the spans of the body stay the spans of the body.',
        );
    }

    public function testPublishingADraftCarriesTheMarksItsHeadingWearsIntoTheQueue(): void
    {
        $this->_repository->method('deleteDraft')->willReturn($this->row(11, 1, 'draft'));
        $this->watch('insertPostWithHistory');

        $this->service()->publishDraft(11);

        $post = $this->asked('insertPostWithHistory')[0];
        $this->assertSame(
            $this->marks('italic', 6, 4)->toArray(),
            $post->titleFormatting->toArray(),
            'A move that keeps the content keeps the marks of the heading with it.',
        );
        $this->assertSame(
            $this->marks('bold', 0, 5)->toArray(),
            $post->formatting->toArray(),
            'And leaves the spans of the body where they were.',
        );
        $this->assertSame('Записанный заголовок', $post->title);
    }

    public function testSchedulingADraftCarriesTheMarksItsHeadingWearsIntoTheQueue(): void
    {
        $this->_repository->method('findDraft')->willReturn($this->row(11, 1, 'draft'));
        $this->_repository->method('deleteDraft')->willReturn($this->row(11, 1, 'draft'));
        $this->watch('insertPostWithHistory');

        $this->service()->scheduleDraft(11, '2026-10-05 10:00:00', 'UTC');

        $post = $this->asked('insertPostWithHistory')[0];
        $this->assertSame($this->marks('italic', 6, 4)->toArray(), $post->titleFormatting->toArray());
        $this->assertSame($this->marks('bold', 0, 5)->toArray(), $post->formatting->toArray());
    }

    public function testPublishingADeletedPartCarriesTheMarksItsHeadingWears(): void
    {
        $this->_repository->method('deleteDeleted')->willReturn($this->row(21, 1, 'deleted'));
        $this->watch('insertPostWithHistory');

        $this->service()->publishDeleted(21);

        $post = $this->asked('insertPostWithHistory')[0];
        $this->assertSame($this->marks('italic', 6, 4)->toArray(), $post->titleFormatting->toArray());
        $this->assertSame($this->marks('bold', 0, 5)->toArray(), $post->formatting->toArray());
    }

    public function testSchedulingADeletedPartCarriesTheMarksItsHeadingWears(): void
    {
        $this->_repository->method('deleteDeleted')->willReturn($this->row(21, 1, 'deleted'));
        $this->watch('insertPostWithHistory');

        $this->service()->scheduleDeleted(21, '2026-10-05 10:00:00', 'UTC');

        $post = $this->asked('insertPostWithHistory')[0];
        $this->assertSame($this->marks('italic', 6, 4)->toArray(), $post->titleFormatting->toArray());
        $this->assertSame($this->marks('bold', 0, 5)->toArray(), $post->formatting->toArray());
    }

    public function testAMarkThatDoesNotFitTheHeadingIsRefused(): void
    {
        $this->_repository->expects($this->never())->method('createDraft');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Сущность 0 выходит за границы текста.');

        $this->service()->saveDraft(
            self::BODY,
            [],
            new MessageEntities(),
            new LinkButtons(),
            'Альфа',
            $this->marks('bold', 0, 9),
        );
    }

    public function testAMarkWithoutAnAddressIsRefusedForAHeadingToo(): void
    {
        $this->_repository->expects($this->never())->method('createDraft');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ссылке сущности 0 не хватает http(s)-адреса.');

        $this->service()->saveDraft(
            self::BODY,
            [],
            new MessageEntities(),
            new LinkButtons(),
            self::HEADING,
            new MessageEntities([['type' => 'text_link', 'offset' => 0, 'length' => 4, 'url' => '']]),
        );
    }
}
