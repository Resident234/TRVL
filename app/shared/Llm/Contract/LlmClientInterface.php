<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

use app\shared\Llm\Dto\LlmReply;
use app\shared\Llm\Infrastructure\LlmRequestException;

/**
 * The only thing this project asks of a model: send these messages, give me the
 * answer. A unit test replaces it with a fixture, so no unit ever reaches the
 * network, and the timeout of every call is the setting the page edits rather
 * than a number the transport chose.
 */
interface LlmClientInterface
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param string|null $modelOverride when non-null, overrides the model from settings for this call
     * @throws LlmRequestException when the proxy refused, timed out or answered nonsense
     */
    public function chat(array $messages, int $timeout, ?string $modelOverride = null): LlmReply;
}
