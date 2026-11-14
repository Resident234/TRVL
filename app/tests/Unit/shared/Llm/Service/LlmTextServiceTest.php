<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Service\LlmTextService;
use app\shared\Llm\Service\TextStyleSampleReader;
use app\shared\Settings\Service\PublicationSettingsService;
use app\tests\Unit\shared\Settings\Service\InMemorySettingsRepository;
use app\tests\Unit\shared\Settings\Service\ListedStylesStub;
use Codeception\Test\Unit;
use RuntimeException;

final class LlmTextServiceTest extends Unit
{
    private const ANSWER = '{"text":"Привет, мир.","entities":[{"type":"bold","offset":0,"length":6}]}';

    private function settings(array $values = []): PublicationSettingsService
    {
        return new PublicationSettingsService(
            new InMemorySettingsRepository($values),
            new ListedStylesStub(['Ватник в Америке']),
        );
    }

    private function service(array $answers, array $values = []): array
    {
        $stub = new LlmClientStub($answers);
        $reader = new TextStyleSampleReader(sys_get_temp_dir() . '/llm-service-no-styles');
        $service = new LlmTextService($stub, $this->settings($values), $reader);

        return [$service, $stub];
    }

    public function testThePromptFromTheSettingIsTheSystemMessage(): void
    {
        [$service, $stub] = $this->service(
            [self::ANSWER],
            ['llmPromptSpelling' => 'Проверь текст как корректор и верни JSON.'],
        );

        $service->spelling('Привет мир.', '[]');

        $system = $stub->seen[0][0];
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('Проверь текст как корректор', $system['content']);
    }

    public function testTheContractBlockIsAppendedByTheCode(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling('Привет мир.', '[]');

        $system = $stub->seen[0][0];
        $this->assertStringContainsString(LlmTextService::ANSWER_CONTRACT, $system['content']);
        $this->assertStringContainsString('"entities"', $system['content'], 'Контракт обязывает JSON с метками.');
    }

    public function testTheTextAndItsMarksRideInTheUserMessage(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling(
            'Привет мир.',
            '[{"type":"italic","offset":0,"length":6}]',
        );

        $user = $stub->seen[0][1];
        $this->assertSame('user', $user['role']);
        $this->assertStringContainsString('Привет мир.', $user['content']);
        $this->assertStringContainsString('"italic"', $user['content']);
        $this->assertStringContainsString('UTF-16', $user['content'], 'Единицы смещений названы в блоке.');
    }

    public function testAPartWithoutMarksSaysSo(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling('Привет мир.', '');

        $this->assertStringContainsString(
            'Меток у этого текста нет.',
            $stub->seen[0][1]['content'],
            'Пустой список меток не должен читаться моделью как «сбрось всё».',
        );
    }

    public function testSpellingIsOneCall(): void
    {
        [$service, $stub] = $this->service([self::ANSWER]);

        $service->spelling('Привет мир.', '[]');

        $this->assertSame(1, $stub->calls);
    }

    public function testTheTimeoutComesFromTheSetting(): void
    {
        [$service, $stub] = $this->service([self::ANSWER], ['llmTimeoutSeconds' => '45']);

        $service->spelling('Привет мир.', '[]');

        $this->assertSame([45], $stub->timeouts);
    }

    public function testTheAnswerBecomesTextAndFormatting(): void
    {
        [$service] = $this->service([self::ANSWER]);

        $answer = $service->spelling('Привет мир.', '[]');

        $this->assertSame('Привет, мир.', $answer->text);
        $this->assertSame(
            '[{"type":"bold","offset":0,"length":6}]',
            $answer->formattingJson(),
        );
    }

    public function testAnEmptyEntitiesListIsANormalAnswer(): void
    {
        [$service] = $this->service(['{"text":"Привет, мир.","entities":[]}']);

        $this->assertSame('[]', $service->spelling('Привет мир.', '[]')->formattingJson());
    }

    public function testAFencedJsonStillReads(): void
    {
        [$service] = $this->service(["```json\n" . self::ANSWER . "\n```"]);

        $this->assertSame('Привет, мир.', $service->spelling('Привет мир.', '[]')->text);
    }

    public function testAnAnswerThatIsNotJsonIsRefused(): void
    {
        // 1 primary + 3 fallback models — all return the same bad answer.
        [$service] = $this->service(array_fill(0, 4, 'Я подумал и вот что скажу.'));

        $this->expectException(LlmRequestException::class);
        $service->spelling('Привет мир.', '[]');
    }

    public function testAnAnswerWithoutTextIsRefused(): void
    {
        // 1 primary + 3 fallback models — all return the same bad answer.
        [$service] = $this->service(array_fill(0, 4, '{"entities":[]}'));

        $this->expectException(LlmRequestException::class);
        $service->spelling('Привет мир.', '[]');
    }

    public function testMarksThatDoNotFitTheAnsweredTextAreRefused(): void
    {
        $bad = '{"text":"Коротко.","entities":[{"type":"bold","offset":0,"length":90}]}';
        // 1 primary + 3 fallback models — all return the same bad answer.
        [$service] = $this->service(array_fill(0, 4, $bad));

        try {
            $service->spelling('Привет мир.', '[]');
            $this->fail('Отказ на несядящиеся метки не прозвучал.');
        } catch (LlmRequestException $e) {
            $this->assertStringContainsString('метк', mb_strtolower($e->getMessage()));
        }
    }

    public function testAMarkTypeTheChannelDoesNotKnowIsDroppedSilently(): void
    {
        [$service] = $this->service(['{"text":"Привет, мир.","entities":[{"type":"spoiler","offset":0,"length":6}]}']);

        $this->assertSame('[]', $service->spelling('Привет мир.', '[]')->formattingJson());
    }
}
