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

/**
 * The free dialog: one call per reply, the instruction of the code instead of a
 * setting, and the window of twelve that decides how much of the conversation the
 * model is shown.
 */
final class LlmTextServiceChatTest extends Unit
{
    private const STYLE = 'Ватник в Америке';

    private const ANSWER = '{"text":"Коротко: братишка, мы в Америке.","entities":[{"type":"bold","offset":7,"length":9}]}';

    private LlmClientStub $_stub;

    private LlmTextService $_service;

    protected function _before(): void
    {
        $this->service(['llmBaseUrl' => 'http://host.docker.internal:31415/v1']);
    }

    /**
     * @param array<string, string> $values
     * @param string[] $answers
     */
    private function service(array $values, array $answers = []): void
    {
        $this->_stub = new LlmClientStub($answers === [] ? [self::ANSWER] : $answers);
        $settings = new PublicationSettingsService(
            new InMemorySettingsRepository($values + [
                'llmApiKey' => 'ключ',
                'llmModel' => 'auto',
                'llmTimeoutSeconds' => '45',
                'activeTextStyle' => self::STYLE,
            ]),
            new ListedStylesStub([self::STYLE]),
        );
        $this->_service = new LlmTextService(
            $this->_stub,
            $settings,
            new TextStyleSampleReader(sys_get_temp_dir() . '/llm-service-no-styles'),
        );
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function userMessage(array $history = []): string
    {
        $this->_service->chat('сократи текст', 'Братишка, мы в Америке.', '', $history);

        return (string) $this->_stub->seen[0][1]['content'];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function history(int $count): array
    {
        $messages = [];
        for ($index = 0; $index < $count; $index++) {
            $messages[] = [
                'role' => $index % 2 === 0 ? 'user' : 'assistant',
                'content' => sprintf('реплика %02d', $index),
            ];
        }

        return $messages;
    }

    public function testOneCallPerReply(): void
    {
        $answer = $this->_service->chat('сократи текст', 'Братишка, мы в Америке.', '', []);

        $this->assertSame(1, $this->_stub->calls);
        $this->assertSame([45], $this->_stub->timeouts);
        $this->assertSame('Коротко: братишка, мы в Америке.', $answer->text);
    }

    public function testTheSystemPromptIsTheInstructionOfTheCode(): void
    {
        $this->userMessage();

        $messages = $this->_stub->seen[0];
        $system = (string) $messages[0]['content'];

        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertStringStartsWith('Перед тобой — текст части публикации и команда автора к нему.', $system);
        $this->assertStringContainsString(LlmTextService::ANSWER_CONTRACT, $system);
    }

    public function testTheDialogueUsesNeitherOfTheTwoEditablePrompts(): void
    {
        $this->service(
            ['llmPromptSpelling' => 'ПРОМТ КОРРЕКТОРА', 'llmPromptStyle' => 'ПРОМТ СТИЛЯ'],
        );

        $this->userMessage();

        $system = (string) $this->_stub->seen[0][0]['content'];

        $this->assertStringNotContainsString('ПРОМТ КОРРЕКТОРА', $system);
        $this->assertStringNotContainsString('ПРОМТ СТИЛЯ', $system);
    }

    public function testTheCommandTravelsInTheUserMessage(): void
    {
        $user = $this->userMessage($this->history(2));

        $this->assertStringContainsString('Команда: сократи текст', $user);
        $this->assertGreaterThan(
            strpos($user, '--- конец разговора ---'),
            strpos($user, 'Команда: '),
            'Команда идёт за блоком истории: модель читает разговор до того, как ей прикажут.',
        );
    }

    public function testThePartAndItsMarksTravelTogether(): void
    {
        $this->_service->chat(
            'перепиши этот абзац',
            'Братишка, мы в Америке.',
            '[{"type":"italic","offset":0,"length":8}]',
            [],
        );

        $user = (string) $this->_stub->seen[0][1]['content'];

        $this->assertStringContainsString('Братишка, мы в Америке.', $user);
        $this->assertStringContainsString('"italic"', $user);
        $this->assertStringContainsString('UTF-16', $user);
    }

    public function testBothSpeakersAreNamedInTheBlock(): void
    {
        $user = $this->userMessage([
            ['role' => 'user', 'content' => 'сократи текст'],
            ['role' => 'assistant', 'content' => 'Коротко: братишка, мы в Америке.'],
        ]);

        $this->assertStringContainsString('Вы: сократи текст', $user);
        $this->assertStringContainsString('Модель: Коротко: братишка, мы в Америке.', $user);
        $this->assertStringContainsString(
            "Вы: сократи текст\nМодель: Коротко: братишка, мы в Америке.",
            $user,
            'Реплики идут в блоке от старых к новым: модель не видит ответа раньше своей команды.',
        );
    }

    public function testAHistoryShorterThanTheWindowGoesInWhole(): void
    {
        $user = $this->userMessage($this->history(6));

        $this->assertSame(6, substr_count($user, 'реплика '));
        $this->assertStringContainsString('реплика 00', $user);
        $this->assertStringContainsString('реплика 05', $user);
        $this->assertStringContainsString(
            "Модель: реплика 03\nВы: реплика 04",
            $user,
            'Реплики идут в порядке разговора, а не группами по говорящим.',
        );
    }

    public function testOnlyTheLastTwelveRepliesOfTheWindowGoIn(): void
    {
        $user = $this->userMessage($this->history(20));

        $this->assertSame(12, substr_count($user, 'реплика '));
        $this->assertStringNotContainsString('реплика 07', $user);
        $this->assertStringContainsString('реплика 08', $user);
        $this->assertStringContainsString('реплика 19', $user);
    }

    public function testTheTwoHistoryNumbersAreConstantsOfTheCode(): void
    {
        $this->assertSame(12, LlmTextService::HISTORY_WINDOW);
        $this->assertSame(50, LlmTextService::HISTORY_SHOWN);
    }

    public function testAnEmptyHistoryLeavesNoBlock(): void
    {
        $user = $this->userMessage([]);

        $this->assertStringNotContainsString('Разговор до этой команды', $user);
    }

    public function testAnUnparseableAnswerIsRefused(): void
    {
        // 1 primary + 3 fallback models — all return the same bad answer.
        $bad = 'Я подумал и вот что скажу.';
        $this->service(['llmBaseUrl' => 'http://host.docker.internal:31415/v1'], array_fill(0, 4, $bad));

        $this->expectException(LlmRequestException::class);
        $this->_service->chat('убери упоминания', 'Братишка, мы в Америке.', '', []);
    }
}
