<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Telegram\Dto;

use app\shared\Telegram\Dto\LinkButton;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class LinkButtonTest extends Unit
{
    public function testFromArrayTrimsBothHalvesOfThePair(): void
    {
        $button = LinkButton::fromArray(['text' => "  Пройти опрос\n", 'url' => ' https://example.com/poll ']);

        $this->assertSame(['text' => 'Пройти опрос', 'url' => 'https://example.com/poll'], $button->toArray());
    }

    public function testAMissingHalfOfTheRequestIsAnEmptyString(): void
    {
        $this->assertSame(['text' => '', 'url' => ''], LinkButton::fromArray([])->toArray());
        $this->assertSame(['text' => '', 'url' => ''], LinkButton::fromArray(['text' => null, 'url' => null])->toArray());
    }

    public function testOnlyAFullPairIsAButton(): void
    {
        $this->assertTrue((new LinkButton())->isEmpty());
        $this->assertFalse((new LinkButton('Пройти опрос', 'https://example.com/poll'))->isEmpty());
    }

    public function testFromStoredReadsTheColumnInAnyOfItsShapes(): void
    {
        $json = '{"text":"Пройти опрос","url":"https://example.com/poll"}';

        $this->assertSame($json, LinkButton::fromStored($json)->toJson());
        $this->assertSame($json, LinkButton::fromStored(['text' => 'Пройти опрос', 'url' => 'https://example.com/poll'])->toJson());
        $this->assertTrue(LinkButton::fromStored(null)->isEmpty());
        $this->assertTrue(LinkButton::fromStored('')->isEmpty());
        $this->assertTrue(LinkButton::fromStored('{}')->isEmpty());
        $this->assertTrue(LinkButton::fromStored('{oops}')->isEmpty());
    }

    public function testAnEmptyPairNeedsNothingAndPasses(): void
    {
        $button = new LinkButton();
        $button->assertValid();

        $this->assertTrue($button->isEmpty());
    }

    public function testAHalfFilledPairIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Кнопке-ссылке нужны и надпись, и адрес');

        (new LinkButton('Пройти опрос', ''))->assertValid();
    }

    public function testTheLabelLimitIsCountedInBytesNotInLetters(): void
    {
        (new LinkButton(str_repeat('а', 32), 'https://example.com/poll'))->assertValid();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Надпись кнопки не может быть длиннее 64 байт (сейчас 66).');

        (new LinkButton(str_repeat('а', 33), 'https://example.com/poll'))->assertValid();
    }

    public function testTheAddressOfAButtonHasToBeOneTelegramOpens(): void
    {
        (new LinkButton('Открыть', 'http://example.com'))->assertValid();
        (new LinkButton('Открыть', 'https://example.com/poll?x=1&y=2'))->assertValid();
        (new LinkButton('Открыть', 'tg://resolve?domain=trvl'))->assertValid();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Адрес кнопки должен начинаться с http://, https:// или tg://.');

        (new LinkButton('Открыть', 'javascript:alert(1)'))->assertValid();
    }

    public function testAnOverlongAddressIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Адрес кнопки не может быть длиннее 2048 символов.');

        (new LinkButton('Открыть', 'https://example.com/' . str_repeat('a', 2048)))->assertValid();
    }

    public function testJsonKeepsTheLabelReadable(): void
    {
        $this->assertSame(
            '{"text":"Пройти опрос","url":"https://example.com/poll"}',
            (new LinkButton('Пройти опрос', 'https://example.com/poll'))->toJson(),
        );
    }
}
