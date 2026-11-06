<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One answer of the chat endpoint: the message, the reason the model stopped, and
 * the usage the proxy reported. The reading of the payload lives here because the
 * shape is the API's, not this project's, and a caller that gets a reply never
 * has to look inside a JSON tree again.
 */
final readonly class LlmReply
{
    public function __construct(
        public string $text,
        public string $finishReason,
        public int $promptTokens,
        public int $completionTokens,
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

        return new self(
            $message['content'],
            isset($choice['finish_reason']) ? (string)$choice['finish_reason'] : '',
            isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : 0,
            isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : 0,
        );
    }
}
