<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmAnswer;
use app\shared\Llm\Dto\LlmChatMessageData;
use app\shared\Llm\Dto\LlmReply;
use app\shared\Llm\Dto\StyleSampleData;
use app\shared\Llm\Infrastructure\LlmModelProbeRepository;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Settings\Service\PublicationSettingsService;
use app\shared\Telegram\Dto\MessageEntities;
use InvalidArgumentException;
use Yii;

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

    /**
     * Appended to the style prompt only: the rewriter must not drop any
     * text_link that the source entities carry. Spelling and chat do not need
     * this rule — spelling never rewrites content, and chat already says "keep
     * facts unchanged".
     */
    private const STYLE_LINK_RULE = <<<'TEXT'

        Все text_link из присланных меток обязательно перенеси в ответ с тем же "url" —
        ни одну ссылку нельзя убирать или заменять словами без сохранения URL.
        TEXT;

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly PublicationSettingsService $settings,
        private readonly TextStyleSampleReader $reader,
        private readonly ?LlmModelProbeRepository $probeRepo = null,
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
     * replies, the part with its marks and the command over them all. The history
     * comes from the oldest reply to the newest, and the window takes its tail.
     *
     * @param array<int, array{role: string, content: string}> $history
     * @throws LlmRequestException when the answer does not parse or its marks do not describe the text
     */
    public function chat(
        string $command,
        string $text,
        string $formattingJson,
        array $history,
        ?string $modelOverride = null,
    ): LlmAnswer {
        $started = microtime(true);
        $model = $modelOverride ?? (string)$this->settings->value('llmModelChat');
        if ($model === 'auto') {
            $model = null;
        }

        $messages = [
            ['role' => 'system', 'content' => self::CHAT_INSTRUCTION . self::ANSWER_CONTRACT],
            [
                'role' => 'user',
                'content' => $this->historyBlock($history)
                    . $this->sourceBlock($text, $formattingJson)
                    . '<last_command>ПОСЛЕДНЯЯ КОМАНДА — главная, она важнее всех реплик разговора: '
                    . $command . '</last_command>',
            ],
        ];

        try {
            $reply = $this->call($messages, $model);
            $answer = $this->answerOf($reply);
            $applied = $this->commandApplied($command, $text, $answer->text);
            $this->metrics('chat', $reply, [
                'parseStrategy' => 'primary',
                'commandApplied' => $applied,
                'latencyMs' => (int)round((microtime(true) - $started) * 1000),
            ]);
            if ($applied) {
                return $answer;
            }
            $reason = 'no_op';
        } catch (LlmRequestException $e) {
            $reason = 'format';
        }

        // The first answer did not do what was asked: put the same question to
        // several models at once and take the best answer instead of whichever
        // fallback happens to answer first.
        $candidates = $this->councilModels($model);
        if ($candidates === []) {
            return $this->ask($messages, $model);
        }

        $answer = $this->council($messages, $command, $text, $candidates, $started);
        $this->metrics('chat.council', $answer->debug, [
            'parseStrategy' => 'council',
            'commandApplied' => true,
            'fallbacks' => count($candidates),
            'latencyMs' => (int)round((microtime(true) - $started) * 1000),
            'firstAttempt' => $reason,
        ]);

        return $answer;
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

    /** How many extra models the council asks when the first answer is weak. */
    public const COUNCIL_SIZE = 2;

    /**
     * The instruction of the free dialog. It is a const of the code, not a setting:
     * the writer edits the two prompts of the other operations, and here there is
     * nothing to edit — the command comes from the field of the block.
     */
    private const CHAT_INSTRUCTION = <<<'TEXT'
        Перед тобой — текст части публикации, разговор и последняя команда автора.
        Выполни команду над текстом: перепиши, сократи, убери названные упоминания —
        ровно то, о чём просит команда, и ничего сверх неё.
        Единственный актуальный текст — блок <current_text>. Реплики «Модель:» в
        разговоре это только предложения, которые автор мог не принять: они НЕ
        являются состоянием документа и не должны возвращаться в ответе, если их
        нет в <current_text> или если команда прямо их не называет.
        Блок <last_command> имеет приоритет над разговором: если прошлая реплика
        спорит с командой, выполняй команду.
        Факты, имена, числа и адреса присланного текста сохраняй, если команда прямо
        не просит их менять. Не соглашайся с утверждением автора, если оно
        противоречит тексту: верни текст без изменений и сообщи об этом полем
        "status":"cannot_verify" вместо выдуманной правки.
        Если у тебя нет инструмента проверить утверждение (карты, сайт, базу), не
        пиши, что проверил: сообщи поле "status":"cannot_verify" и не меняй текст.
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
        $content = $this->callWithFallback([
            ['role' => 'system', 'content' => self::PORTRAIT_INSTRUCTION],
            ['role' => 'user', 'content' => $sample->block],
        ]);

        return mb_substr(trim($content->text), 0, self::MAX_PORTRAIT_CHARS);
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
            ['role' => 'system', 'content' => $prompt . self::STYLE_LINK_RULE . self::ANSWER_CONTRACT],
            ['role' => 'user', 'content' => $block . $this->sourceBlock($text, $formattingJson)],
        ]);
    }

    /**
     * Hard-coded fallback list used when the probe table is empty (first deploy
     * or probe has never run). Once `llm/probe-models` runs, the table takes
     * over and this list is never consulted again.
     */
    private const FORMAT_FALLBACK_MODELS = [
        'mistral-small-3.2-24b',
        'gemini-3.8-flash',
        'qwen-3.8-27b',
    ];

    /**
     * The ordered list of fallback models to try when `auto` returns a response
     * that does not parse. Reads from the probe table when available; falls back
     * to the hard-coded list when the table is empty (first deploy).
     *
     * @return string[]
     */
    private function fallbackModels(): array
    {
        if ($this->probeRepo !== null) {
            $models = $this->probeRepo->okModels(20);
            if ($models !== []) {
                return $models;
            }
        }

        return self::FORMAT_FALLBACK_MODELS;
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
    private function call(array $messages, ?string $modelOverride = null): LlmReply
    {
        $reply = $this->client->chat($messages, $this->timeout(), $modelOverride);
        $this->logReply($reply, $modelOverride);

        return $reply;
    }

    /**
     * The reasoning and the finish reason of one reply go to a log category of
     * their own: the raw transport body is too big to read for this, and the
     * browser shows the same fields when the debug flag is on.
     */
    private function logReply(LlmReply $reply, ?string $requestedModel): void
    {
        Yii::warning([
            'event' => 'llm.reply',
            'requestedModel' => $requestedModel,
            'model' => $reply->actualModel,
            'finishReason' => $reply->finishReason,
            'reasoning' => $reply->reasoning,
            'promptTokens' => $reply->promptTokens,
            'completionTokens' => $reply->completionTokens,
        ], 'llm.reasoning');
    }

    /**
     * One compact record per operation, so parse failures, no-ops and fallback
     * rates can be compared between models without reading the raw bodies.
     *
     * @param array<string, mixed> $extra
     */
    private function metrics(string $task, ?LlmReply $reply, array $extra): void
    {
        Yii::info(array_merge([
            'event' => 'llm.metrics',
            'task' => $task,
            'model' => $reply?->actualModel,
            'finishReason' => $reply?->finishReason,
            'reasoningField' => $reply !== null && $reply->reasoning !== '',
            'promptTokens' => $reply === null ? 0 : $reply->promptTokens,
            'completionTokens' => $reply === null ? 0 : $reply->completionTokens,
        ], $extra), 'llm.metrics');
    }

    /**
     * The models of the council: the model of this dialog first, then the ones
     * that passed the edit probe, fastest last. A dialog never rotates to a
     * model the router happened to pick for one call.
     *
     * @return string[]
     */
    private function councilModels(?string $primary): array
    {
        $models = $primary === null ? [] : [$primary];
        if ($this->probeRepo !== null) {
            $models = array_merge($models, $this->probeRepo->bestModels(self::COUNCIL_SIZE + 1));
        }
        if ($models === []) {
            $models = self::FORMAT_FALLBACK_MODELS;
        }

        $models = array_values(array_unique(array_filter(
            $models,
            static fn (string $model): bool => $model !== '' && $model !== 'auto',
        )));

        return array_slice($models, 0, self::COUNCIL_SIZE + ($primary !== null ? 1 : 0));
    }

    /**
     * Did the answer actually do what the command asked? A quoted fragment the
     * command names and that is still in the answer means the edit was not
     * made; an unchanged text is a no-op unless the command asked for one.
     */
    private function commandApplied(string $command, string $source, string $answer): bool
    {
        $source = trim($source);
        $answer = trim($answer);
        if ($source === '' || $answer === '') {
            return false;
        }

        if (preg_match('~оставь|не меняй|без изменений~ui', $command) === 1) {
            return $source === $answer;
        }
        if ($source === $answer) {
            return false;
        }

        preg_match_all('~[«"]([^»"]{2,80})[»"]~u', $command, $matches);
        foreach ($matches[1] as $target) {
            if (str_contains($source, $target) && str_contains($answer, $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Asks the same question of several models in one flight, rejects answers
     * that did not apply the command, and returns the best of the rest. Every
     * candidate and its score go to `llm.council` for later analysis.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param string[] $models
     */
    private function council(
        array $messages,
        string $command,
        string $source,
        array $models,
        float $started,
    ): LlmAnswer {
        $replies = $this->client->chatMany($messages, $this->timeout(), $models);
        $best = null;
        $bestScore = -1000;
        $lastReason = 'no_reply';
        $candidates = [];

        foreach ($replies as $model => $reply) {
            $answer = null;
            $score = -1000;
            $reason = 'no_reply';

            if ($reply instanceof LlmReply) {
                try {
                    $answer = $this->answerOf($reply);
                    $applied = $this->commandApplied($command, $source, $answer->text);
                    $score = $applied ? 100 : 40;
                    $reason = $applied ? 'applied' : 'no_op';
                    if ($reply->finishReason !== 'stop') {
                        $score -= 20;
                    }
                    $delta = abs(mb_strlen($answer->text) - mb_strlen($source));
                    $score -= min(20, intdiv($delta, 10));
                } catch (LlmRequestException $e) {
                    $reason = $e->getMessage();
                }
            }

            $lastReason = $reason;
            $candidates[] = [
                'model' => (string)$model,
                'score' => $score,
                'reason' => $reason,
                'textLength' => $answer === null ? 0 : mb_strlen($answer->text),
                'finishReason' => $reply instanceof LlmReply ? $reply->finishReason : '',
            ];
            if ($answer !== null && $score > $bestScore) {
                $best = $answer;
                $bestScore = $score;
            }
        }

        Yii::warning([
            'event' => 'llm.council',
            'candidates' => $candidates,
            'latencyMs' => (int)round((microtime(true) - $started) * 1000),
        ], 'llm.council');

        if ($best === null) {
            throw new LlmRequestException(
                'Ни одна из доступных моделей не вернула подходящего ответа: ' . $lastReason . '.',
            );
        }

        return $best;
    }

    /**
     * Like `call()` but retries with fallback models when the primary model
     * returns an empty or whitespace-only response. Used for portrait() where
     * the answer is free prose, not JSON — the only failure mode worth retrying
     * is a blank reply from a router that picked an unusable model.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function callWithFallback(array $messages): LlmReply
    {
        $result = $this->call($messages);
        if (trim($result->text) !== '') {
            return $result;
        }

        foreach ($this->fallbackModels() as $fallbackModel) {
            try {
                $result = $this->call($messages, $fallbackModel);
                if (trim($result->text) !== '') {
                    return $result;
                }
            } catch (LlmRequestException) {
                // Skip to the next fallback.
            }
        }

        // Return whatever we have — portrait() will check for empty and refuse.
        return $result;
    }

    /**
     * Sends the messages and parses the JSON contract, retrying with fallback
     * models when the configured model returns a response that does not parse.
     * Network errors and timeouts are not retried here — the client already
     * retries those inside its own budget.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function ask(array $messages, ?string $modelOverride = null): LlmAnswer
    {
        // First attempt: the per-task model when one is named, else the setting.
        try {
            return $this->answerOf($this->call($messages, $modelOverride));
        } catch (LlmRequestException $first) {
            if (!$this->isFormatError($first)) {
                throw $first;
            }

            $lastError = $first;
        }

        // Fallback: try each known-good model until one returns valid JSON.
        foreach ($this->fallbackModels() as $fallbackModel) {
            try {
                return $this->answerOf($this->call($messages, $fallbackModel));
            } catch (LlmRequestException $e) {
                $lastError = $e;
                // If a fallback itself times out or is refused, skip to the next.
            }
        }

        throw new LlmRequestException(
            'Ни одна из доступных моделей не вернула ответ в правильном формате: '
            . $lastError->getMessage(),
        );
    }

    /**
     * A format error is one where the model answered but the response did not
     * parse as the JSON contract: no text field, marks that do not fit the text.
     * Network errors, timeouts and refused keys are not format errors — retrying
     * them with a different model would not help and would only waste time.
     */
    private function isFormatError(LlmRequestException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'не разбирается')
            || str_contains($msg, 'нет текста')
            || str_contains($msg, 'не сходятся');
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

        return "<current_text>\n" . $text . "\n\n" . $marks . "\n</current_text>\n\n";
    }

    /**
     * The conversation as the model sees it: the last window of replies, oldest
     * first, each named by who wrote it. No history — no block, so a first reply
     * is not preceded by an empty section.
     *
     * @param array<int, array{role: string, content: string, status?: string}> $history
     */
    private function historyBlock(array $history): string
    {
        if ($history === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($history, -self::HISTORY_WINDOW) as $message) {
            $who = $message['role'] === 'assistant'
                ? (($message['status'] ?? '') === LlmChatMessageData::STATUS_ACCEPTED
                    ? 'Модель (принято, это уже в тексте): '
                    : (($message['status'] ?? '') === LlmChatMessageData::STATUS_REJECTED
                        ? 'Модель (отклонено, не используй): '
                        : 'Модель (предложение, могло быть не принято): '))
                : 'Вы: ';
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
    private function answerOf(LlmReply $reply): LlmAnswer
    {
        $payload = $this->jsonPiece($reply->text);
        if ($payload === null || !isset($payload['text']) || !is_string($payload['text'])) {
            throw new LlmRequestException('Ответ LLM не разбирается: в нём нет текста.');
        }

        $raw = [];
        if (isset($payload['entities']) && is_array($payload['entities'])) {
            $raw = $payload['entities'];
        }

        $answer = new LlmAnswer($payload['text'], MessageEntities::fromArray($raw), $reply);
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
