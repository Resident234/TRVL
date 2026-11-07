<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmReply;
use RuntimeException;

/**
 * The client without a socket: it hands out the prepared answers in order,
 * remembers every message list it was given and every timeout it was asked to
 * respect, and counts the calls — which is how a test proves that stylization
 * is two calls and spelling is one.
 */
final class LlmClientStub implements LlmClientInterface
{
    public int $calls = 0;

    /** @var array<int, array<int, array{role: string, content: string}>> */
    public array $seen = [];

    /** @var int[] */
    public array $timeouts = [];

    /** @var string[] */
    private array $answers;

    /**
     * @param string[] $answers content of each answer, in call order
     */
    public function __construct(array $answers)
    {
        $this->answers = $answers;
    }

    public function chat(array $messages, int $timeout): LlmReply
    {
        $this->calls++;
        $this->seen[] = $messages;
        $this->timeouts[] = $timeout;

        if ($this->answers === []) {
            throw new RuntimeException('Вызовов к прокси больше, чем ответов в фикстуре.');
        }

        $content = array_shift($this->answers);

        return new LlmReply((string) $content, 'stop', 100, 20);
    }
}
