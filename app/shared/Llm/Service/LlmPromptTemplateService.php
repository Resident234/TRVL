<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmPromptTemplateRepositoryInterface;
use InvalidArgumentException;

/**
 * The prompt templates a reader keeps from the dialog of the AI. They live in
 * their own table, apart from the tunables and from the two prompts edited on
 * the settings page: the latter are the configuration of the model, these are
 * the text the reader wants to reuse.
 *
 * The code is a digest of the text, so keeping the same words twice finds the
 * same row. The prefix is kept from the time templates were rows of the
 * settings table, so the migration could move them without changing a code.
 */
final class LlmPromptTemplateService
{
    public const PROMPT_TEMPLATE_PREFIX = 'llmPromptTemplate_';
    public const PROMPT_TEMPLATE_MAX_CHARS = 4000;

    public function __construct(
        private readonly LlmPromptTemplateRepositoryInterface $repository,
    ) {
    }

    public static function promptTemplateCode(string $text): string
    {
        return self::PROMPT_TEMPLATE_PREFIX . md5(trim($text));
    }

    /**
     * The templates already saved, as code => text.
     *
     * @return array<string, string>
     */
    public function promptTemplates(): array
    {
        return $this->repository->all();
    }

    public function hasPromptTemplate(string $text): bool
    {
        return isset($this->repository->all()[self::promptTemplateCode($text)]);
    }

    /**
     * Stores the text of a dialog line as a prompt template if it is not
     * there yet. A repeat answers with the same code and writes nothing.
     *
     * @return array{code: string, text: string, created: bool}
     */
    public function savePromptTemplate(string $text): array
    {
        $normalized = trim($text);
        if ($normalized === '' || mb_strlen($normalized) > self::PROMPT_TEMPLATE_MAX_CHARS) {
            throw new InvalidArgumentException(
                'Шаблон промта не может быть пустым или длиннее '
                . self::PROMPT_TEMPLATE_MAX_CHARS . ' знаков.',
            );
        }

        $code = self::promptTemplateCode($normalized);
        $created = !isset($this->repository->all()[$code]);
        if ($created) {
            $this->repository->save($code, $normalized, gmdate('Y-m-d H:i:s'));
        }

        return ['code' => $code, 'text' => $normalized, 'created' => $created];
    }
}
