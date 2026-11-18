<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

/**
 * Storage of the prompt templates a reader saved from the AI dialog. This is
 * user content in a table of its own, not one of the tunables of the settings
 * page, so the settings form never reads or overwrites it.
 */
interface LlmPromptTemplateRepositoryInterface
{
    /**
     * Every saved template as code => text.
     *
     * @return array<string, string>
     */
    public function all(): array;

    /**
     * Writes one template, identified by the digest of its text. A repeat of
     * the same code only refreshes the text and the timestamp.
     */
    public function save(string $code, string $text, string $now): void;
}
