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
 * is sent as the page wrote it. A heading that wears marks of its own is sent with
 * exactly those marks, and the rule of the bold line steps back for them.
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

    public function testWholeHeadingIsBoldOverTheBodyOfItsNumberedPart(): void
    {
        $entities = $this->part("Часть 2\n\nБраво текст.", 'Часть 2. Альфа текст.')
            ->messageEntities();

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

    public function testWholeHeadingOfAPartNumberedAtItsEndIsBold(): void
    {
        $entities = $this->part('Браво текст.', 'Альфа текст. Часть 1')->messageEntities();

        $this->assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 20]],
            $entities->toArray(),
        );
    }

    public function testMarkedHeadingOfAPartIsSentWithoutTheBoldOverItsWholeLine(): void
    {
        $marked = MessageEntities::fromArray([['type' => 'bold', 'offset' => 0, 'length' => 5]]);

        $entities = $this->part('Браво текст.', 'Альфа текст. Часть 1', new MessageEntities(), $marked)
            ->messageEntities();

        $this->assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 5]],
            $entities->toArray(),
        );
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
