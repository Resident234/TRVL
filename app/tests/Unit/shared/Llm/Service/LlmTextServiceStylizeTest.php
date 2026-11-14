<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;

final class LlmTextServiceStylizeTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const PORTRAIT = 'Автор рубит предложения коротко, любит обращение «братишка».';

    private const ANSWER = '{"text":"Ну здравствуй, братишка.","entities":[{"type":"bold","offset":15,"length":8}]}';

    private StyleSampleFolder $_folder;

    private LlmTextService $_service;

    private LlmClientStub $_stub;

    protected function _before(): void
    {
        parent::_before();

        $this->_folder = StyleSampleFolder::create(self::STYLE);
    }

    protected function _after(): void
    {
        parent::_after();

        $this->_folder->purge();
    }

    private function service(array $answers, array $values = []): void
    {
        $this->_stub = new LlmClientStub($answers);
        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository($values + ['activeTextStyle' => self::STYLE]),
            new ListedStylesStub([self::STYLE]),
        );

        $this->_service = new LlmTextService($this->_stub, $settings, $this->_folder->reader());
    }

    public function testStylizationIsExactlyTwoCalls(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[]');

        $this->assertSame(2, $this->_stub->calls);
    }

    public function testTheFirstCallCarriesTheSampleAndNoPromptOfTheSetting(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['llmPromptStyle' => 'Перепиши за автора «{style}».']);

        $this->_service->stylize('Привет мир.', '[]');

        $first = $this->_stub->seen[0];
        $this->assertStringContainsString('Образец стиля «Ватник в Америке»', $first[1]['content']);
        $this->assertStringNotContainsString('Перепиши за автора', $first[0]['content']);
        $this->assertStringStartsWith(
            'Перед тобой — приложенный образец текстов автора.',
            $first[0]['content'],
            'Первый вызов идёт со служебной инструкцией кода, а не с пустым системным блоком.',
        );
        $this->assertStringNotContainsString(
            LlmTextService::ANSWER_CONTRACT,
            $first[0]['content'],
            'Портрет стиля — проза, контракт ему не нужен.',
        );
    }

    public function testTheSampleRespectsTheCeilingOfTheSetting(): void
    {
        // Минимум настройки `llmStyleSampleChars` — 2000 знаков, и `normalize()`
        // поднимает до него любое меньшее значение: потолок в 600 знаков настройка
        // не хранит, поэтому берут самый малый разрешённый.
        $this->service([self::PORTRAIT, self::ANSWER], ['llmStyleSampleChars' => '2000']);

        $this->_service->stylize('Привет мир.', '[]');

        $sample = $this->_stub->seen[0][1]['content'];
        // Фикстура каталога даёт четыре сообщения по 902 знака; под потолок в 2000
        // знаков становятся два самых свежих — «3» и «2», то есть 1804.
        $this->assertStringContainsString('— 1804 знаков', $sample);
        $this->assertStringContainsString('3:' . str_repeat('а', 900), $sample);
        $this->assertStringContainsString('2:' . str_repeat('а', 900), $sample);
        $this->assertStringNotContainsString('1:' . str_repeat('а', 900), $sample);
    }

    public function testThePortraitOfTheFirstCallTravelsIntoTheSecond(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[]');

        $this->assertStringContainsString(self::PORTRAIT, $this->_stub->seen[1][1]['content']);
    }

    public function testALongPortraitIsCutToTheBudget(): void
    {
        $this->service([str_repeat('р', 3000), self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[]');

        $this->assertStringNotContainsString(
            str_repeat('р', 1201),
            $this->_stub->seen[1][1]['content'],
            'Портрет длиннее 1200 знаков в запрос не уходит целиком.',
        );
    }

    public function testTheSecondCallCarriesTheStylePromptWithTheFolderName(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['llmPromptStyle' => 'Ты пишешь за автора «{style}».']);

        $this->_service->stylize('Привет мир.', '[]');

        $system = $this->_stub->seen[1][0]['content'];
        $this->assertStringContainsString('Ты пишешь за автора «Ватник в Америке».', $system);
        $this->assertStringNotContainsString('{style}', $system);
        $this->assertStringContainsString(LlmTextService::ANSWER_CONTRACT, $system);
    }

    public function testTheSecondCallCarriesThePartAndItsMarks(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $this->_service->stylize('Привет мир.', '[{"type":"italic","offset":0,"length":6}]');

        $user = $this->_stub->seen[1][1]['content'];
        $this->assertStringContainsString('Привет мир.', $user);
        $this->assertStringContainsString('"italic"', $user);
    }

    public function testTheAnswerOfTheSecondCallBecomesTheAnswer(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);

        $answer = $this->_service->stylize('Привет мир.', '[]');

        $this->assertSame('Ну здравствуй, братишка.', $answer->text);
        $this->assertSame('[{"type":"bold","offset":15,"length":8}]', $answer->formattingJson());
    }

    public function testAnEmptyPortraitRefusesBeforeTheSecondCall(): void
    {
        // 1 primary + 3 fallback attempts all return empty — callWithFallback
        // exhausts all options and portrait() gets '' back, which stylizeIn refuses.
        $this->service(array_fill(0, 4, ''));

        try {
            $this->_service->stylize('Привет мир.', '[]');
            $this->fail('Отказ на пустой разбор не прозвучал.');
        } catch (LlmRequestException $e) {
            $this->assertSame(4, $this->_stub->calls, 'Все fallback-вызовы были сделаны перед отказом.');
        }
    }

    public function testAnUnreadableSampleRefusesWithoutAnyCall(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER]);
        unlink($this->_folder->path . '/Ватник в Америке/result.json');

        try {
            $this->_service->stylize('Привет мир.', '[]');
            $this->fail('Отказ на нечитаемый образец не прозвучал.');
        } catch (StyleSampleUnavailableException $e) {
            $this->assertStringContainsString(self::STYLE, $e->getMessage());
            $this->assertSame(0, $this->_stub->calls);
        }
    }

    public function testNoStyleChosenRefuses(): void
    {
        $this->service([self::PORTRAIT, self::ANSWER], ['activeTextStyle' => '']);

        $this->expectException(LlmRequestException::class);
        $this->_service->stylize('Привет мир.', '[]');
    }

    public function testStylizeInHonoursTheStyleItIsHandedAndNotTheSetting(): void
    {
        $this->service(
            [self::PORTRAIT, self::ANSWER],
            ['llmPromptStyle' => 'Ты пишешь за автора «{style}».', 'activeTextStyle' => 'Совсем другой'],
        );

        $this->_service->stylizeIn(self::STYLE, 'Привет мир.', '[]');

        $this->assertStringContainsString('Образец стиля «Ватник в Америке»', $this->_stub->seen[0][1]['content']);
        $this->assertStringContainsString('Ты пишешь за автора «Ватник в Америке».', $this->_stub->seen[1][0]['content']);
        $this->assertStringNotContainsString(
            'Совсем другой',
            (string) json_encode($this->_stub->seen, JSON_UNESCAPED_UNICODE),
            'Ряд очереди называет стиль — рабочий не должен подставлять вместо него активный.',
        );
    }
}
