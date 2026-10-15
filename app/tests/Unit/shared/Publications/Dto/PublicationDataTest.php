<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Dto;

use app\shared\Publications\Dto\PublicationData;
use app\shared\Telegram\Dto\MessageEntities;
use Codeception\Test\Unit;

/**
 * The message a part of a split publication is sent as. The page writes the line
 * «Часть N» into the text of the part and — while both switches of the form stand
 * on the same way — the same number in front of the heading, so the composition has
 * to see that the heading already carries it and take the line out of the body
 * without naming the part twice.
 */
final class PublicationDataTest extends Unit
{
    private function part(string $text, string $title, MessageEntities $formatting = new MessageEntities()): PublicationData
    {
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
}
