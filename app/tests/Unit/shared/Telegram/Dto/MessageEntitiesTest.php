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

    public function testAHeadingOpensTheMessageInBoldOverTheSpansItMoves(): void
    {
        $entities = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 4]])
            ->underHeading('Заголовок');

        $this->assertSame([
            ['type' => 'bold', 'offset' => 0, 'length' => 9],
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
            ['type' => 'bold', 'offset' => 0, 'length' => 18],
            ['type' => 'bold', 'offset' => 20, 'length' => 7],
        ], $entities->toArray());
    }

    public function testAHeadingIsCountedInUtf16UnitsToo(): void
    {
        $entities = (new MessageEntities())->underHeading('Ищем✈️');

        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 6]], $entities->toArray());
    }
}
