<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmReply;
use RuntimeException;

/**
 * The client without a socket: it hands out the prepared _answers in order,
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
    private array $_answers;

    /**
     * @param string[] $_answers content of each answer, in call order
     */
    public function __construct(array $_answers)
    {
        $this->_answers = $_answers;
    }

    /** @var list<string|null> */
    public array $models = [];

    /** @var list<string[]> */
    public array $councilModels = [];

    public function chat(array $messages, int $timeout, ?string $modelOverride = null): LlmReply
    {
        $this->calls++;
        $this->seen[] = $messages;
        $this->timeouts[] = $timeout;
        $this->models[] = $modelOverride;

        if ($this->_answers === []) {
            throw new RuntimeException('Вызовов к прокси больше, чем ответов в фикстуре.');
        }

        $content = array_shift($this->_answers);

        return new LlmReply((string) $content, 'stop', 100, 20);
    }

    public function chatMany(array $messages, int $timeout, array $models): array
    {
        $this->calls++;
        $this->seen[] = $messages;
        $this->timeouts[] = $timeout;
        $this->councilModels[] = $models;

        $replies = [];
        foreach ($models as $model) {
            if ($this->_answers === []) {
                $replies[$model] = null;
                continue;
            }
            $replies[$model] = new LlmReply((string)array_shift($this->_answers), 'stop', 100, 20, '', $model);
        }

        return $replies;
    }
}
