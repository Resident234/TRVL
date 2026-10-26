<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Dto;

use app\shared\Publications\Dto\PublicationData;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;

/**
 * The message a part of a split publication is sent as. The page writes the line
 * «Часть N» into the text of the part and — while both switches of the form stand
 * on the same way — the same number either in front of the heading or at its end,
 * so the composition has to see that the heading already carries it and take the
 * line out of the body without naming the part twice. Where the number stands in
 * the heading, the text of the part brings no number line at all, and the heading
 * is sent as the page wrote it. A heading is sent with exactly the marks it was
 * saved with: the boxes of the heading block stamp them over its whole line before
 * the form goes out, and a heading saved without marks goes out plain.
 */
final class PublicationDataTest extends Unit
{
    private function part(
        string $text,
        string $title,
        MessageEntities $formatting = new MessageEntities(),
        MessageEntities $titleFormatting = new MessageEntities(),
    ): PublicationData {
        return new PublicationData(
            id: 1,
            text: $text,
            imageUrls: [],
            telegramId: null,
            publishedAt: null,
            createdAt: '2026-10-14 00:00:00',
            updatedAt: '2026-10-14 00:00:00',
            formatting: $formatting,
            title: $title,
            titleFormatting: $titleFormatting,
        );
    }

    public function testHeadingThatAlreadyOpensWithItsNumberIsNotNumberedAgain(): void
    {
        $message = $this->part("Часть 2\n\nБраво текст.", 'Часть 2. Альфа текст.')->messageText();

        $this->assertSame("Часть 2. Альфа текст.\n\nБраво текст.", $message);
    }

    public function testHeadingThatAlreadyOpensWithItsNumberKeepsTheBodyUnderIt(): void
    {
        [$heading, $body] = $this->part("Часть 2\n\nБраво текст.", 'Часть 2. Альфа текст.')
            ->headingAndBody();

        $this->assertSame('Часть 2. Альфа текст.', $heading);
        $this->assertSame('Браво текст.', $body);
    }

    public function testBoldStampedOverTheWholeHeadingOfANumberedPartGoesOutFirst(): void
    {
        $stamped = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 21]]);

        $entities = $this->part(
            "Часть 2\n\nБраво текст.",
            'Часть 2. Альфа текст.',
            new MessageEntities(),
            $stamped,
        )->messageEntities();

        $this->assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 21]],
            $entities->toArray(),
        );
    }

    public function testHeadingWithoutTheNumberStillTakesItAtTheEnd(): void
    {
        $message = $this->part("Часть 1\n\nБраво текст.", 'Альфа текст.')->messageText();

        // The shape a record saved before the number moved in front of the heading
        // is sent as: the line goes to the end of the heading, and the join is a
        // plain '. ' — the dot the heading already ends with is repeated there.
        $this->assertSame("Альфа текст.. Часть 1\n\nБраво текст.", $message);
    }

    public function testHeadingOfAPartWithoutANumberKeepsItsTextWhole(): void
    {
        $message = $this->part('Обычное тело.', 'Заголовок.')->messageText();

        $this->assertSame("Заголовок.\n\nОбычное тело.", $message);
    }

    public function testHeadingNumberedAtItsEndIsSentWithTheNumberOnce(): void
    {
        $message = $this->part('Браво текст.', 'Альфа текст. Часть 1')->messageText();

        // The shape the pair of radios under the numbering writes when the reader
        // stands the number at the end: the number line stays out of the text, so
        // the composition finds none to move and the heading goes out as written.
        $this->assertSame("Альфа текст. Часть 1\n\nБраво текст.", $message);
    }

    public function testBoldStampedOverTheWholeHeadingOfAPartNumberedAtItsEndGoesOut(): void
    {
        $stamped = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 20]]);

        $entities = $this->part('Браво текст.', 'Альфа текст. Часть 1', new MessageEntities(), $stamped)
            ->messageEntities();

        $this->assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 20]],
            $entities->toArray(),
        );
    }

    public function testHeadingWithoutMarksOfItsOwnSendsNoHeadingMarks(): void
    {
        // The state every box of the heading block stands off in: the heading goes
        // out plain, and only the spans of the body move behind it.
        $body = MessageEntities::fromArray([['type' => 'italic', 'offset' => 0, 'length' => 5]]);

        $entities = $this->part('Браво текст.', 'Заголовок', $body)->messageEntities();

        $this->assertSame(
            [['type' => 'italic', 'offset' => 11, 'length' => 5]],
            $entities->toArray(),
        );
    }

    public function testHeadingOfAPartWearsOnlyTheMarksItWasStampedWith(): void
    {
        $marked = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 5]]);

        $entities = $this->part('Браво текст.', 'Альфа текст. Часть 1', new MessageEntities(), $marked)
            ->messageEntities();

        $this->assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 5]],
            $entities->toArray(),
        );
    }

    public function testMarksOverTheWholeOfAHeadingCoverTheNumberItsLineGains(): void
    {
        // The heading of a record written before the number moved into the field of
        // the heading: the line the channel is given is the words of the field plus
        // the number the body hands over, and a span the reader stood over the whole
        // of the heading is stood over that line — a mark that stops short of the
        // number leaves the number of a marked heading the one plain word of it.
        $stamped = MessageEntities::fromArray([
            ['type' => 'bold', 'offset' => 0, 'length' => 9],
            ['type' => 'italic', 'offset' => 0, 'length' => 9],
        ]);

        $entities = $this->part("Часть 1\n\nБраво текст.", 'Заголовок', new MessageEntities(), $stamped)
            ->messageEntities();

        $this->assertSame([
            ['type' => 'bold', 'offset' => 0, 'length' => 18],
            ['type' => 'italic', 'offset' => 0, 'length' => 18],
        ], $entities->toArray());
    }

    public function testTheBodyOfAHeadingThatGrewItsNumberStandsWhereItStood(): void
    {
        // The heading gains the number out of the body, so the body loses exactly
        // that much of the distance it travels: the widening reaches the marks of the
        // heading and nothing behind the blank line.
        $stamped = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 9]]);
        $body = MessageEntities::fromArray([['type' => 'italic', 'offset' => 9, 'length' => 5]]);

        $entities = $this->part("Часть 1\n\nБраво текст.", 'Заголовок', $body, $stamped)->messageEntities();

        $this->assertSame([
            ['type' => 'bold', 'offset' => 0, 'length' => 18],
            ['type' => 'italic', 'offset' => 20, 'length' => 5],
        ], $entities->toArray());
    }

    public function testAMarkOverPartOfAHeadingDoesNotReachItsNumber(): void
    {
        // A span the reader set over four of the nine letters of a heading claims
        // those four letters, not the line: the number its line gains belongs to no
        // part of what he marked.
        $stamped = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 4]]);

        $entities = $this->part("Часть 1\n\nБраво текст.", 'Заголовок', new MessageEntities(), $stamped)
            ->messageEntities();

        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 4]], $entities->toArray());
    }

    public function testAMarkOverAHeadingOfNoNumberIsLeftAtTheLengthItWasSavedWith(): void
    {
        $stamped = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 9]]);

        $entities = $this->part('Браво текст.', 'Заголовок', new MessageEntities(), $stamped)->messageEntities();

        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 9]], $entities->toArray());
    }

    public function testMarksOfTheHeadingAndMarksOfItsBodyStandInTheMessageTogether(): void
    {
        $marked = MessageEntities::fromArray([
            ['type' => 'text_link', 'offset' => 6, 'length' => 6, 'url' => 'https://example.com/a'],
        ]);
        $body = MessageEntities::fromArray([['type' => 'italic', 'offset' => 0, 'length' => 5]]);

        $entities = $this->part('Браво текст.', 'Альфа текст.', $body, $marked)->messageEntities();

        $this->assertSame(
            [
                ['type' => 'text_link', 'offset' => 6, 'length' => 6, 'url' => 'https://example.com/a'],
                ['type' => 'italic', 'offset' => 14, 'length' => 5],
            ],
            $entities->toArray(),
        );
    }
}
