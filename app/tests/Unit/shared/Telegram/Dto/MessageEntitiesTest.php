<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Telegram\Dto;

use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class MessageEntitiesTest extends Unit
{
    public function testFromArrayKeepsOnlyTheTypesTelegramRenders(): void
    {
        $entities = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 4],
            ['type' => 'spoiler', 'offset' => 5, 'length' => 3],
            ['type' => 'underline'],
            'not an entity',
        ]);

        $this->assertSame([
            ['type' => 'bold', 'offset' => 0, 'length' => 4],
            ['type' => 'underline', 'offset' => 0, 'length' => 0],
        ], $entities->toArray());
    }

    public function testTextLinkKeepsItsUrlAndNothingElseDoes(): void
    {
        $entities = MessageEntities::fromArray([
            ['type' => 'text_link', 'offset' => 0, 'length' => 4, 'url' => 'https://example.com/a'],
            ['type' => 'italic', 'offset' => 5, 'length' => 2, 'url' => 'https://example.com/b'],
        ]);

        $this->assertSame('[{"type":"text_link","offset":0,"length":4,"url":"https://example.com/a"},'
            . '{"type":"italic","offset":5,"length":2}]', $entities->toJson());
    }

    public function testFromStoredReadsTheColumnInAnyOfItsShapes(): void
    {
        $json = '[{"type":"bold","offset":0,"length":3}]';

        $this->assertSame($json, MessageEntities::fromStored($json)->toJson());
        $this->assertSame($json, MessageEntities::fromStored([['type' => 'bold', 'offset' => 0, 'length' => 3]])->toJson());
        $this->assertTrue(MessageEntities::fromStored(null)->isEmpty());
        $this->assertTrue(MessageEntities::fromStored('')->isEmpty());
        $this->assertTrue(MessageEntities::fromStored('{oops}')->isEmpty());
    }

    public function testUtf16LengthCountsAnEmojiAsTwoUnits(): void
    {
        $this->assertSame(0, MessageEntities::utf16Length(''));
        $this->assertSame(6, MessageEntities::utf16Length('жирным'));
        $this->assertSame(4, MessageEntities::utf16Length('a😀b'));
    }

    public function testSliceCutsTheSpanThatSticksOutAndDropsTheOneOutside(): void
    {
        $entities = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 6],
            ['type' => 'italic', 'offset' => 8, 'length' => 4],
            ['type' => 'text_link', 'offset' => 10, 'length' => 6, 'url' => 'https://example.com'],
        ]);

        $this->assertSame(
            '[{"type":"bold","offset":0,"length":4},{"type":"italic","offset":6,"length":4},'
                . '{"type":"text_link","offset":8,"length":2,"url":"https://example.com"}]',
            $entities->slice(2, 10)->toJson(),
        );
        $this->assertSame(
            '[{"type":"bold","offset":0,"length":4},{"type":"italic","offset":6,"length":4},'
                . '{"type":"text_link","offset":8,"length":6,"url":"https://example.com"}]',
            $entities->slice(2)->toJson(),
        );
        $this->assertSame('[]', $entities->slice(20)->toJson());
    }

    public function testNestedSpansFitTheTextTheyStandOver(): void
    {
        $entities = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 6],
            ['type' => 'italic', 'offset' => 2, 'length' => 3],
            ['type' => 'code', 'offset' => 7, 'length' => 3],
        ]);

        $entities->assertFitsText('Привет мир');

        $this->assertCount(3, $entities->toArray());
    }

    public function testSpansStartingAtTheSamePlaceAreInOrderToo(): void
    {
        $entities = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 6],
            ['type' => 'italic', 'offset' => 0, 'length' => 3],
        ]);

        $entities->assertFitsText('Привет мир');

        $this->assertCount(2, $entities->toArray());
    }

    public function testAListOutOfOffsetOrderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Сущность 1 идёт раньше предыдущей.');

        MessageEntities::fromArray([
            ['type' => 'italic', 'offset' => 7, 'length' => 3],
            ['type' => 'bold', 'offset' => 0, 'length' => 6],
        ])->assertFitsText('Привет мир');
    }

    public function testAnEmptySpanIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Сущность 0 пуста.');

        MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 0]])
            ->assertFitsText('Привет мир');
    }

    public function testASpanPastTheEndOfTheTextIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Сущность 0 выходит за границы текста.');

        MessageEntities::fromArray([['type' => 'bold', 'offset' => 7, 'length' => 4]])
            ->assertFitsText('Привет мир');
    }

    public function testTheSpanIsCountedInUtf16UnitsNotInBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Сущность 0 выходит за границы текста.');

        MessageEntities::fromArray([['type' => 'bold', 'offset' => 3, 'length' => 2]])
            ->assertFitsText('a😀b');
    }

    public function testALinkWithoutAnAddressIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ссылке сущности 0 не хватает http(s)-адреса.');

        MessageEntities::fromArray([
            ['type' => 'text_link', 'offset' => 0, 'length' => 6, 'url' => 'javascript:alert(1)'],
        ])->assertFitsText('Привет мир');
    }

    public function testAHeadingWithoutMarksOfItsOwnMovesTheBodyAndStaysPlain(): void
    {
        $entities = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 4]])
            ->underHeading('Заголовок');

        $this->assertSame([
            ['type' => 'bold', 'offset' => 11, 'length' => 4],
        ], $entities->toArray());
    }

    public function testNoHeadingLeavesTheListWhereItWas(): void
    {
        $entities = MessageEntities::fromArray([['type' => 'italic', 'offset' => 3, 'length' => 2]])
            ->underHeading('');

        $this->assertSame([['type' => 'italic', 'offset' => 3, 'length' => 2]], $entities->toArray());
    }

    public function testTheNumberLineTheHeadingTookOverIsNotMovedTwice(): void
    {
        // «Часть 1» plus the blank line is 9 of the units the stored span counts,
        // and the heading that took it over is 18 long.
        $entities = MessageEntities::fromArray([['type' => 'bold', 'offset' => 9, 'length' => 7]])
            ->underHeading('Заголовок. Часть 1', "\n\n", 9);

        $this->assertSame([
            ['type' => 'bold', 'offset' => 20, 'length' => 7],
        ], $entities->toArray());
    }

    public function testAHeadingIsCountedInUtf16UnitsToo(): void
    {
        // The wing is two of the units Telegram counts, so the body of the message
        // starts eight in, not six.
        $entities = MessageEntities::fromArray([['type' => 'italic', 'offset' => 0, 'length' => 2]])
            ->underHeading('Ищем✈️');

        $this->assertSame([['type' => 'italic', 'offset' => 8, 'length' => 2]], $entities->toArray());
    }

    public function testAHeadingWithMarksOfItsOwnWearsThemAndNothingElse(): void
    {
        $entities = MessageEntities::fromArray([['type' => 'italic', 'offset' => 0, 'length' => 5]])
            ->underHeading(
                'Заголовок',
                "\n\n",
                0,
                MessageEntities::fromArray([['type' => 'underline', 'offset' => 3, 'length' => 4]]),
            );

        $this->assertSame([
            ['type' => 'underline', 'offset' => 3, 'length' => 4],
            ['type' => 'italic', 'offset' => 11, 'length' => 5],
        ], $entities->toArray());
    }

    public function testTheBodyOfAMarkedHeadingMovesAsFarAsTheBodyOfAnUnmarkedOne(): void
    {
        // The same heading that took the number line over, now wearing marks of
        // its own: the heading gains the two spans the reader set, and the stored
        // span moves exactly where it moved before — behind the heading, over the
        // blank line, less the number.
        $entities = MessageEntities::fromArray([['type' => 'bold', 'offset' => 9, 'length' => 7]])
            ->underHeading(
                'Заголовок. Часть 1',
                "\n\n",
                9,
                MessageEntities::fromArray([
                    ['type' => 'italic', 'offset' => 0, 'length' => 4],
                    ['type' => 'underline', 'offset' => 11, 'length' => 5],
                ]),
            );

        $this->assertSame([
            ['type' => 'italic', 'offset' => 0, 'length' => 4],
            ['type' => 'underline', 'offset' => 11, 'length' => 5],
            ['type' => 'bold', 'offset' => 20, 'length' => 7],
        ], $entities->toArray());
    }

    public function testAHeadingWearsEveryMarkTheBlockStampedOverItsWholeLine(): void
    {
        // The four boxes of the heading block arrive as four spans over the whole
        // line, in the order the page keeps spans in, and the body moves behind
        // them the way it moves behind one.
        $own = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 9],
            ['type' => 'italic', 'offset' => 0, 'length' => 9],
            ['type' => 'underline', 'offset' => 0, 'length' => 9],
            ['type' => 'strikethrough', 'offset' => 0, 'length' => 9],
        ]);

        $entities = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 4]])
            ->underHeading('Заголовок', "\n\n", 0, $own);

        $this->assertSame([
            ['type' => 'bold', 'offset' => 0, 'length' => 9],
            ['type' => 'italic', 'offset' => 0, 'length' => 9],
            ['type' => 'underline', 'offset' => 0, 'length' => 9],
            ['type' => 'strikethrough', 'offset' => 0, 'length' => 9],
            ['type' => 'bold', 'offset' => 11, 'length' => 4],
        ], $entities->toArray());
    }

    public function testAWholeHeadingMarkGrowsOverTheNumberItsLineGains(): void
    {
        // The list of a heading is measured over the words of the field it was saved
        // from, while the line the channel is given also carries the number the body
        // hands over. A span that stood from the beginning of the heading to its end
        // is moved to the end of that line; a span of part of the heading, and a link
        // of the whole of it, keep the address they were saved with.
        $widened = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 9],
            ['type' => 'text_link', 'offset' => 0, 'length' => 9, 'url' => 'https://example.com/a'],
            ['type' => 'italic', 'offset' => 2, 'length' => 7],
        ])->widenedToHeading('Заголовок', 'Заголовок. Часть 1');

        $this->assertSame([
            ['type' => 'bold', 'offset' => 0, 'length' => 18],
            ['type' => 'text_link', 'offset' => 0, 'length' => 18, 'url' => 'https://example.com/a'],
            ['type' => 'italic', 'offset' => 2, 'length' => 7],
        ], $widened->toArray());
    }

    public function testAHeadingWhoseLineGainedNothingKeepsItsMarksAsTheyWereSaved(): void
    {
        $saved = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 9]]);

        $widened = $saved->widenedToHeading('Заголовок', 'Заголовок');

        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 9]], $widened->toArray());
    }

    public function testTheLineAGrowingMarkReachesIsCountedInUtf16Units(): void
    {
        // The wing is two of the units Telegram counts, so the heading of the field
        // is six of them wide, and the line it grows into — the same heading, the
        // join, and «Часть 1» — is fifteen of them, not thirteen of the letters.
        $widened = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 6]])
            ->widenedToHeading('Ищем✈️', 'Ищем✈️. Часть 1');

        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 15]], $widened->toArray());
    }

    public function testMarksOfAnEmptyHeadingGoWhereTheHeadingWent(): void
    {
        $own = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 4]]);

        $entities = MessageEntities::fromArray([['type' => 'italic', 'offset' => 3, 'length' => 2]])
            ->underHeading('', "\n\n", 0, $own);

        $this->assertSame([['type' => 'italic', 'offset' => 3, 'length' => 2]], $entities->toArray());
    }
}
