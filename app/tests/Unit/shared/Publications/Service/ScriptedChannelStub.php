<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Telegram\Dto\LinkButtons;
use app\shared\Telegram\Dto\MessageEntities;
use app\shared\Telegram\Infrastructure\TelegramApiException;

/**
 * The channel the publishing queue sends through, with a number of refusals
 * scripted per message text. It counts how many times a send of a text was
 * asked for and remembers the message id a successful send handed back, so a
 * test can tell one attempt per record from three.
 */
final class ScriptedChannelStub
{
    /** @var array<string, int> message text => how many sends of it must fail */
    private array $_failures;

    /** @var array<string, int> message text => sends asked for */
    public array $attempts = [];

    /** @var array<string, int> message text => message id of the sent message */
    public array $sent = [];

    /**
     * @param array<string, int> $failures message text => how many sends of it must fail
     */
    public function __construct(array $failures)
    {
        $this->_failures = $failures;
    }

    public function publishText(
        string $text,
        MessageEntities $entities = new MessageEntities(),
        LinkButtons $buttons = new LinkButtons(),
    ): int {
        $this->attempts[$text] = ($this->attempts[$text] ?? 0) + 1;

        if (($this->_failures[$text] ?? 0) >= $this->attempts[$text]) {
            throw new TelegramApiException(
                'Bad Request: failed to send message #0 with the error message "WEBPAGE_MEDIA_EMPTY"',
                400,
            );
        }

        $this->sent[$text] = 5000 + count($this->sent);

        return $this->sent[$text];
    }
}
