<?php

declare(strict_types=1);

namespace app\shared\Telegram\Infrastructure;

use RuntimeException;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use Throwable;

final class TelegramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode > 0 ? $errorCode : 0, $previous);
    }

    public static function fromTelegramException(TelegramException $e): self
    {
        $retryAfter = null;
        if ($e->hasParameter('retry_after')) {
            $retryAfter = (int)$e->getParameters()['retry_after'];
        }

        return new self($e->getMessage(), $e->getCode(), $retryAfter, $e);
    }

    /**
     * A delete of a message the channel does not have answers 400 with this
     * wording and nothing structured to test by; the capitalisation of the
     * description is not stable either.
     */
    public function isMessageGone(): bool
    {
        return stripos($this->getMessage(), 'message to delete not found') !== false;
    }
}
