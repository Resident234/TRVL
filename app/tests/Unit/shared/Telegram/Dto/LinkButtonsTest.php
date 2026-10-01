<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Telegram\Dto;

use app\shared\Telegram\Dto\LinkButton;
use app\shared\Telegram\Dto\LinkButtons;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class LinkButtonsTest extends Unit
{
    public function testTwoListsOfTheFormPairUpByPosition(): void
    {
        $buttons = LinkButtons::fromPairs(
            ['Пройти опрос', 'Открыть'],
            ['https://example.com/poll', 'tg://resolve?domain=trvl'],
        );

        $this->assertSame(
            [
                ['text' => 'Пройти опрос', 'url' => 'https://example.com/poll'],
                ['text' => 'Открыть', 'url' => 'tg://resolve?domain=trvl'],
            ],
            $buttons->toArray(),
        );
    }

    public function testTheRowsOfTheFormAreTrimmedAndItsEmptyRowsDropped(): void
    {
        $buttons = LinkButtons::fromPairs(
            ["  Пройти опрос\n", '', '', 'Открыть'],
            [' https://example.com/poll ', '', '', 'https://example.com/open'],
        );

        $this->assertSame(2, $buttons->count());
        $this->assertSame('Открыть', $buttons->all()[1]->text);
    }

    public function testAHalfRowOfTheFormStaysSoThatValidationCanRefuseIt(): void
    {
        $buttons = LinkButtons::fromPairs(['Пройти опрос', 'Ещё'], ['https://example.com/poll', '']);

        $this->assertSame(2, $buttons->count());
        $this->assertSame('', $buttons->all()[1]->url);
    }

    public function testAListShorterThanItsPairsFillsTheRestWithEmptyStrings(): void
    {
        $buttons = LinkButtons::fromPairs(['Пройти опрос', 'Открыть'], ['https://example.com/poll']);

        $this->assertSame(2, $buttons->count());
        $this->assertSame(['text' => 'Открыть', 'url' => ''], $buttons->all()[1]->toArray());
    }

    public function testFromStoredReadsTheColumnInAnyOfItsShapes(): void
    {
        $json = '[{"text":"Пройти опрос","url":"https://example.com/poll"}]';
        $legacy = '{"text":"Пройти опрос","url":"https://example.com/poll"}';

        $this->assertSame($json, LinkButtons::fromStored($json)->toJson());
        $this->assertSame($json, LinkButtons::fromStored([['text' => 'Пройти опрос', 'url' => 'https://example.com/poll']])->toJson());
        // A row written before the form held a list of buttons holds one object,
        // and the list of it is what the record still means.
        $this->assertSame($json, LinkButtons::fromStored($legacy)->toJson());
        $this->assertSame($json, LinkButtons::fromStored(json_decode($legacy, true))->toJson());
        $this->assertTrue(LinkButtons::fromStored(null)->isEmpty());
        $this->assertTrue(LinkButtons::fromStored('')->isEmpty());
        $this->assertTrue(LinkButtons::fromStored('{}')->isEmpty());
        $this->assertTrue(LinkButtons::fromStored('[]')->isEmpty());
        $this->assertTrue(LinkButtons::fromStored('[{}]')->isEmpty());
        $this->assertTrue(LinkButtons::fromStored('{oops}')->isEmpty());
    }

    public function testAnEmptyListNeedsNothingAndPasses(): void
    {
        $buttons = LinkButtons::empty();
        $buttons->assertValid();

        $this->assertSame(0, $buttons->count());
        $this->assertSame('[]', $buttons->toJson());
    }

    public function testAListIsBoundedByWhatThePortalGivesAMessage(): void
    {
        $rows = array_fill(0, LinkButtons::MAX_BUTTONS, 'https://example.com/poll');
        LinkButtons::fromPairs(array_fill(0, LinkButtons::MAX_BUTTONS, 'Открыть'), $rows)->assertValid();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Под одним сообщением не больше 10 кнопок-ссылок, а их 11.');

        LinkButtons::fromPairs(array_fill(0, 11, 'Открыть'), array_fill(0, 11, 'https://example.com/poll'))->assertValid();
    }

    public function testEveryButtonOfTheListIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Адрес кнопки должен начинаться с http://, https:// или tg://.');

        LinkButtons::fromPairs(
            ['Открыть', 'И ещё'],
            ['https://example.com/open', 'javascript:alert(1)'],
        )->assertValid();
    }

    public function testTheListOfButtonsKeepsTheButtonsThemselves(): void
    {
        $open = new LinkButton('Открыть', 'https://example.com/open');
        $buttons = new LinkButtons([$open]);

        $this->assertSame([$open], $buttons->all());
        $this->assertSame('[{"text":"Открыть","url":"https://example.com/open"}]', $buttons->toJson());
    }
}
