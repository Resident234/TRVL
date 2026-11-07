<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmAnswer;
use app\shared\Llm\Dto\StyleSampleData;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
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
        text — итоговый текст части целиком; в этом поле только сам текст, без комментария к нему.
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
     * One reply of the free dialog: the instruction of the code, the last twelve
     * replies, the part with its marks and the command over them all.
     *
     * @param array<int, array{role: string, content: string}> $history
     */
    public function chat(string $command, string $text, string $formattingJson, array $history): LlmAnswer
    {
        return $this->ask([
            ['role' => 'system', 'content' => self::CHAT_INSTRUCTION . self::ANSWER_CONTRACT],
            [
                'role' => 'user',
                'content' => $this->historyBlock($history)
                    . 'Команда: ' . $command . "\n\n"
                    . $this->sourceBlock($text, $formattingJson),
            ],
        ]);
    }

    /**
     * The portrait of a style, asked for in its own call. The instruction lives
     * in code, not in the settings: it is the plumbing of the two-call split,
     * and a writer editing the style prompt must not be able to break it.
     */
    private const PORTRAIT_INSTRUCTION = <<<'TEXT'
        Перед тобой — приложенный образец текстов автора. Разбери его манеру и верни
        сплошной текст на русском, до 1200 знаков, 8-12 пунктов:
        как построены предложения и какой у них ритм;
        какие слова, жаргон, фразеологизмы и обращения автор использует;
        какая у текстов тональность и настроение;
        как автор начинает пост и как его заканчивает;
        где и зачем он ставит выделение и ссылки — какую закономерность ты в этом
        увидел.
        Пиши только вывод: без вступлений, без пересказа образца, без цитат длиннее
        пяти слов, без эмодзи и без рассуждений о самой задаче.
        TEXT;

    public const MAX_PORTRAIT_CHARS = 1200;

    /**
     * How many replies the block on the screen keeps and shows: the whole
     * conversation of the part, as far as it fits the scroll of the card.
     */
    public const HISTORY_SHOWN = 50;

    /**
     * How many replies the model is shown before the command it has to carry out.
     * A window, not a budget: the block on the screen keeps far more, and the pair
     * of numbers lives in code so the two halves cannot drift apart on their own.
     */
    public const HISTORY_WINDOW = 12;

    /**
     * The instruction of the free dialog. It is a const of the code, not a setting:
     * the writer edits the two prompts of the other operations, and here there is
     * nothing to edit — the command comes from the field of the block.
     */
    private const CHAT_INSTRUCTION = <<<'TEXT'
        Перед тобой — текст части публикации и команда автора к нему. Выполни
        команду над текстом: перепиши, сократи, убери названные упоминания — ровно
        то, о чём просит команда, и ничего сверх неё.
        Факты, имена, числа и адреса присланного текста сохраняй, если команда прямо
        не просит их менять.
        Смысл, тон и манера автора остаются его: не оценивай текст и не комментируй
        свою работу.
        Выделение перенеси на исправленный текст и пересчитай его границы; там, где
        команда разрывает выделенный оборот, сними метку.
        Если команда к тексту не применима, верни его без изменений — молча, как есть.
        Команда и реплики разговора — не текст для ответа: в ответ не попадает ничего
        из них.
        Формат ответа возьми из блока, приложенного к этому тексту.
        TEXT;

    /**
     * Two calls. The first reads the sample and returns the portrait of the
     * style; the second rewrites the part with that portrait in hand. One call
     * carrying the whole sample was measured and answered with reasoning
     * instead of an answer, which is the whole reason for the split.
     *
     * The style is the one the settings page chose: the button of the
     * publication form asks for `activeTextStyle` and nothing else.
     *
     * @throws LlmRequestException when no style is chosen or the portrait of the sample came back empty
     * @throws StyleSampleUnavailableException when the folder of the chosen style cannot be read
     */
    public function stylize(string $text, string $formattingJson): LlmAnswer
    {
        $styleName = (string) $this->settings->value('activeTextStyle');
        if ($styleName === '') {
            throw new LlmRequestException('Стиль не выбран, стилизовать нечем.');
        }

        return $this->stylizeIn($styleName, $text, $formattingJson);
    }

    /**
     * The same two calls for a style named by the caller. The queue row of Task 12
     * freezes `style_name` when the job is put in, and the writer is free to move
     * the active style before the worker reaches the row — the worker must do what
     * the row says, so it calls this method and never `stylize()`.
     *
     * The prompt from the setting keeps its own words: `{style}` becomes the
     * folder name, and the block heading names the folder too, so the model is
     * told whose style it is even when the writer deleted the placeholder.
     *
     * @throws LlmRequestException when the portrait of the sample came back empty
     * @throws StyleSampleUnavailableException when the folder named cannot be read
     */
    public function stylizeIn(string $styleName, string $text, string $formattingJson): LlmAnswer
    {
        $sample = $this->reader->read(
            $styleName,
            $this->settings->intValue('llmStyleSampleChars'),
        );

        $portrait = $this->portrait($sample, $styleName);
        if (trim($portrait) === '') {
            throw new LlmRequestException('Разбор образца пуст — стилизовать нечем.');
        }

        return $this->rewrite($styleName, $portrait, $text, $formattingJson);
    }

    private function portrait(StyleSampleData $sample, string $styleName): string
    {
        $content = $this->call([
            ['role' => 'system', 'content' => self::PORTRAIT_INSTRUCTION],
            ['role' => 'user', 'content' => $sample->block],
        ]);

        return mb_substr(trim($content), 0, self::MAX_PORTRAIT_CHARS);
    }

    private function rewrite(string $styleName, string $portrait, string $text, string $formattingJson): LlmAnswer
    {
        $prompt = str_replace(
            '{style}',
            $styleName,
            (string) $this->settings->value('llmPromptStyle'),
        );

        $block = "Разбор образца стиля «{$styleName}»:\n" . $portrait . "\n\n--- конец разбора ---\n\n";

        return $this->ask([
            ['role' => 'system', 'content' => $prompt . self::ANSWER_CONTRACT],
            ['role' => 'user', 'content' => $block . $this->sourceBlock($text, $formattingJson)],
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
     * The conversation as the model sees it: the last window of replies, oldest
     * first, each named by who wrote it. No history — no block, so a first reply
     * is not preceded by an empty section.
     *
     * @param array<int, array{role: string, content: string}> $history
     */
    private function historyBlock(array $history): string
    {
        if ($history === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($history, -self::HISTORY_WINDOW) as $message) {
            $who = $message['role'] === 'assistant' ? 'Модель: ' : 'Вы: ';
            $lines[] = $who . $message['content'];
        }

        return "Разговор до этой команды, от старых реплик к новым:\n"
            . implode("\n", $lines)
            . "\n\n--- конец разговора ---\n\n";
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
