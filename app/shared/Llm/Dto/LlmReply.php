<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One answer of the chat endpoint: the message, the reason the model stopped, the
 * usage the proxy reported and whatever the provider told us about how it thought.
 *
 * Reasoning comes in several shapes depending on the router and the model:
 * `message.reasoning`, `message.reasoning_content`, `choice.reasoning` and the
 * texts inside `reasoning_details`. All of them are gathered here instead of
 * being lost after the first `->text` in the service.
 */
final readonly class LlmReply
{
    /**
     * @param array<string, mixed> $usage
     */
    public function __construct(
        public string $text,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
        public string $reasoning = '',
        public string $actualModel = '',
        public array $usage = [],
    ) {
    }

    /**
     * The first choice of a decoded response, or nothing at all when the payload
     * carries no message: an error object, an empty `choices`, a null content. The
     * caller turns that into a refusal with the reason of its own.
     *
     * @param array<mixed> $decoded
     */
    public static function fromApi(array $decoded): ?self
    {
        $choice = isset($decoded['choices'][0]) ? $decoded['choices'][0] : null;
        if (!is_array($choice)) {
            return null;
        }

        $message = isset($choice['message']) ? $choice['message'] : null;
        if (!is_array($message) || !isset($message['content']) || !is_string($message['content'])) {
            return null;
        }

        $usage = isset($decoded['usage']) && is_array($decoded['usage']) ? $decoded['usage'] : [];
        $finishReason = isset($choice['finish_reason']) ? $choice['finish_reason'] : null;

        return new self(
            $message['content'],
            is_scalar($finishReason) ? (string)$finishReason : '',
            isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0,
            isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0,
            self::reasoningOf($message, $choice, $decoded),
            self::modelOf($decoded),
            $usage,
        );
    }

    /**
     * @param array<mixed> $message
     * @param array<mixed> $choice
     * @param array<mixed> $decoded
     */
    private static function reasoningOf(array $message, array $choice, array $decoded): string
    {
        $parts = [];
        $sources = [
            $message['reasoning'] ?? null,
            $message['reasoning_content'] ?? null,
            $choice['reasoning'] ?? null,
        ];
        // Some routers put `reasoning` beside the choices, not inside one.
        if (isset($decoded['choices']) && is_array($decoded['choices'])) {
            $sources[] = $decoded['choices']['reasoning'] ?? null;
        }
        foreach ($sources as $value) {
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        $details = $message['reasoning_details'] ?? $choice['reasoning_details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && isset($detail['text']) && is_string($detail['text']) && trim($detail['text']) !== '') {
                    $parts[] = trim($detail['text']);
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function modelOf(array $decoded): string
    {
        foreach ([$decoded['_routed_via']['model'] ?? null, $decoded['model'] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The diagnostic shape sent to the form and written to the debug log.
     *
     * @return array<string, mixed>
     */
    public function debug(): array
    {
        return [
            'model' => $this->actualModel,
            'finishReason' => $this->finishReason,
            'reasoning' => $this->reasoning,
            'promptTokens' => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
        ];
    }
}
