<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmPromptTemplateRepositoryInterface;

/**
 * The template table without PostgreSQL: codes and texts live in a property,
 * and a save overwrites the row of the same code just like the UPSERT does.
 */
final class InMemoryLlmPromptTemplateRepository implements LlmPromptTemplateRepositoryInterface
{
    /** @var array<string, string> code => text */
    public array $values = [];

    /** @var int how many times a save was asked for */
    public int $saves = 0;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function save(string $code, string $text, string $now): void
    {
        $this->saves++;
        $this->values[$code] = $text;
    }
}
