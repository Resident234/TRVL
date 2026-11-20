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

    /**
     * The same question put to several models at once. Keyed by the model
     * actually asked; a model that refused or timed out has a null reply.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param string[] $models
     * @return array<string, LlmReply|null>
     */
    public function chatMany(array $messages, int $timeout, array $models): array;
}
