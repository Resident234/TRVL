<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmAnswer;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\MessageEntities;
use InvalidArgumentException;

/**
 * Assembles a request, sends it through the client, and turns the answer into
 * the text and the marks a part of the form holds.
 *
 * The prompt lives in the settings so the writer can rewrite it; the shape of
 * the answer does not. `ANSWER_CONTRACT` is appended by the code to every
 * operation, so a prompt edited into prose cannot change what comes back, and
 * `spelling()`, `stylize()` and `chat()` all read one contract.
 *
 * An answer is applied whole or not at all: the marks it brings are checked
 * against the text it brings, and a mismatch throws before anything reaches the
 * form. Half-marked text in a publication would reach the channel as a broken
 * record, so there is no partial accept here.
 */
final class LlmTextService
{
    public const ANSWER_CONTRACT = <<<'TEXT'

        Формат ответа — строго один JSON-объект, без пояснений вокруг него и без
        markdown-ограждения:
        {"text":"…","entities":[{"type":"bold","offset":0,"length":5}]}
        text — итоговый текст целиком, одними лишь знаками присланного тебе текста.
        entities — выделение этого текста; offset и length считаются в единицах
        UTF-16, как их считает браузер. Допустимые типы: bold, italic, underline,
        strikethrough, code, text_link (у него обязательно поле "url" с http(s)-адресом).
        Если выделения нет, entities — пустой массив.
        TEXT;

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly PublicationSettingsService $settings,
        private readonly TextStyleSampleReader $reader,
    ) {
    }

    /**
     * One call: the corrector prompt from the setting, the part with its marks,
     * and the answer straight back into the field.
     */
    public function spelling(string $text, string $formattingJson): LlmAnswer
    {
        $prompt = (string) $this->settings->value('llmPromptSpelling');

        return $this->ask([
            ['role' => 'system', 'content' => $prompt . self::ANSWER_CONTRACT],
            ['role' => 'user', 'content' => $this->sourceBlock($text, $formattingJson)],
        ]);
    }

    /**
     * The budget of one call, read from the setting every time: it is the number
     * that keeps a synchronous request inside `fastcgi_read_timeout`.
     */
    private function timeout(): int
    {
        return $this->settings->intValue('llmTimeoutSeconds');
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function call(array $messages): string
    {
        return $this->client->chat($messages, $this->timeout())->text;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function ask(array $messages): LlmAnswer
    {
        return $this->answerOf($this->call($messages));
    }

    /**
     * The part as the model sees it: its text, then its marks in the same units
     * the answer has to bring them back in.
     */
    private function sourceBlock(string $text, string $formattingJson): string
    {
        $entities = MessageEntities::fromStored($formattingJson);
        $marks = $entities->isEmpty()
            ? 'Меток у этого текста нет.'
            : 'Метки этого текста, единицы UTF-16: ' . $entities->toJson();

        return "Текст части:\n" . $text . "\n\n" . $marks;
    }

    /**
     * The contract read back. `fromArray` drops the types the channel cannot
     * render, `assertFitsText` refuses marks that do not describe this text.
     */
    private function answerOf(string $content): LlmAnswer
    {
        $payload = $this->jsonPiece($content);
        if ($payload === null || !isset($payload['text']) || !is_string($payload['text'])) {
            throw new LlmRequestException('Ответ LLM не разбирается: в нём нет текста.');
        }

        $raw = [];
        if (isset($payload['entities']) && is_array($payload['entities'])) {
            $raw = $payload['entities'];
        }

        $answer = new LlmAnswer($payload['text'], MessageEntities::fromArray($raw));
        try {
            $answer->entities->assertFitsText($answer->text);
        } catch (InvalidArgumentException $e) {
            throw new LlmRequestException('Метки ответа не сходятся с его текстом: ' . $e->getMessage() . '.');
        }

        return $answer;
    }

    /**
     * The JSON object out of whatever the model wrapped around it. A fenced
     * answer is the common case with a router model, and unwrapping one fence
     * costs nothing — prose without braces still fails to parse and is refused.
     *
     * @return array<mixed>|null
     */
    private function jsonPiece(string $content): ?array
    {
        $trimmed = trim($content);
        $from = strpos($trimmed, '{');
        $to = strrpos($trimmed, '}');
        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        $decoded = json_decode(substr($trimmed, $from, $to - $from + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
