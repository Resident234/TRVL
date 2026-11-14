<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use RuntimeException;

/**
 * Why a call to the proxy did not bring an answer: the key was refused, the
 * address did not answer, the budget ran out, or the payload had no message in
 * it. The sentence is written for the person standing at the form, and the form
 * shows it unchanged.
 */
final class LlmRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?int $statusCode = null,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
