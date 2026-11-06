<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Settings\Service;

use app\shared\Settings\Service\PublicationSettingsService;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class PublicationSettingsServiceLlmTest extends Unit
{
    /**
     * @param string[] $folders
     */
    private function service(
        InMemorySettingsRepository $repository,
        array $folders = ['Ватник в Америке'],
    ): PublicationSettingsService {
        return new PublicationSettingsService($repository, new ListedStylesStub($folders), '*/5 * * * *');
    }

    /**
     * The whole tunables form, with the defaults of the schema and whatever the test
     * cares about written over them.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function submitted(array $overrides): array
    {
        return array_merge(
            PublicationSettingsService::defaults(),
            ['partsOffsetMinutes' => '5'],
            $overrides,
        );
    }

    public function testSchemaHoldsTheFiveTextCodes(): void
    {
        $schema = PublicationSettingsService::schema();

        // Пять кодов этого задания; два числовых придут в Task 3 и расширят проверку до семи.
        foreach ([
            'llmBaseUrl', 'llmApiKey', 'llmModel',
            'llmPromptSpelling', 'llmPromptStyle',
        ] as $code) {
            $this->assertArrayHasKey($code, $schema, "Код «{$code}» не стоит в схеме.");
        }

        $this->assertSame('text', $schema['llmBaseUrl']['type']);
        $this->assertSame('text', $schema['llmModel']['type']);
        $this->assertSame('textarea', $schema['llmPromptSpelling']['type']);
        $this->assertSame('textarea', $schema['llmPromptStyle']['type']);
        $this->assertTrue($schema['llmApiKey']['secret']);
    }

    public function testDefaultsOfTheConnection(): void
    {
        $defaults = PublicationSettingsService::defaults();

        $this->assertSame('http://host.docker.internal:31415/v1', $defaults['llmBaseUrl']);
        $this->assertSame('', $defaults['llmApiKey']);
        $this->assertSame('auto', $defaults['llmModel']);
    }

    public function testPromptDefaultsCarryTheStylePlaceholder(): void
    {
        $defaults = PublicationSettingsService::defaults();

        $this->assertStringContainsString('{style}', $defaults['llmPromptStyle']);
        $this->assertStringNotContainsString('{style}', $defaults['llmPromptSpelling']);
        $this->assertGreaterThanOrEqual(20, mb_strlen($defaults['llmPromptSpelling']));
        $this->assertGreaterThanOrEqual(20, mb_strlen($defaults['llmPromptStyle']));
        $this->assertLessThanOrEqual(4000, mb_strlen($defaults['llmPromptStyle']));
    }

    public function testFormAcceptsATextAndATextareaValue(): void
    {
        $repository = new InMemorySettingsRepository();

        $this->service($repository)->save($this->submitted([
            'llmBaseUrl' => 'http://host.docker.internal:31415/v1',
            'llmPromptSpelling' => "Проверь текст.\nИ ничего не добавляй.",
        ]));

        $this->assertSame('http://host.docker.internal:31415/v1', $repository->values['llmBaseUrl']);
        $this->assertSame(
            "Проверь текст.\nИ ничего не добавляй.",
            $repository->values['llmPromptSpelling'],
            'Перенос строки внутри промта обязан дожить до базы.',
        );
    }

    public function testFormRefusesAnEmptyPrompt(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmPromptStyle' => '   ']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('«Промт стилизации» нельзя оставить пустым.', $e->getMessage());
        }

        $this->assertSame(0, $repository->saves);
    }

    public function testFormRefusesAPromptShorterThanTheBound(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmPromptSpelling' => 'Проверь.']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                '«Промт проверки орфографии» принимает от 20 до 4000 знаков, передано 8.',
                $e->getMessage(),
            );
        }
    }

    public function testFormRefusesAUrlThatIsNotAnAddress(): void
    {
        $repository = new InMemorySettingsRepository();

        try {
            $this->service($repository)->save($this->submitted(['llmBaseUrl' => 'host.docker.internal:31415']));
            $this->fail('Отказ не прозвучал.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                '«Адрес LLM» надо задать адресом вида http://хост[:порт]/путь, без пробелов.',
                $e->getMessage(),
            );
        }
    }

    public function testEmptyKeyInputKeepsTheStoredKey(): void
    {
        $repository = new InMemorySettingsRepository(['llmApiKey' => 'sk-секрет-из-базы']);

        $this->service($repository)->save($this->submitted(['llmApiKey' => '']));

        $this->assertSame('sk-секрет-из-базы', $repository->values['llmApiKey']);
        $this->assertSame(1, $repository->saves);
    }

    public function testAKeyOfItsOwnReplacesTheStoredOne(): void
    {
        $repository = new InMemorySettingsRepository(['llmApiKey' => 'старый']);

        $this->service($repository)->save($this->submitted(['llmApiKey' => 'новый']));

        $this->assertSame('новый', $repository->values['llmApiKey']);
    }

    public function testValueOfAnUnreadablePromptFallsBackToTheDefault(): void
    {
        $repository = new InMemorySettingsRepository(['llmPromptSpelling' => '   ']);

        $service = $this->service($repository);

        $this->assertSame(
            PublicationSettingsService::defaults()['llmPromptSpelling'],
            $service->value('llmPromptSpelling'),
            'Пустое хранимое значение не должно дойти до формы.',
        );
    }
}
