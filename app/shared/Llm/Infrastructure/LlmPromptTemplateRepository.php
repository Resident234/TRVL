<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmPromptTemplateRepositoryInterface;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage of the prompt templates of the AI dialog.
 */
final class LlmPromptTemplateRepository implements LlmPromptTemplateRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = $this->db
            ->createCommand('SELECT code, text FROM {{%llm_prompt_template}} ORDER BY code')
            ->queryAll(PDO::FETCH_ASSOC);

        $templates = [];
        foreach ($rows as $row) {
            $templates[(string)$row['code']] = (string)$row['text'];
        }

        return $templates;
    }

    public function save(string $code, string $text, string $now): void
    {
        $this->db
            ->createCommand(
                'INSERT INTO {{%llm_prompt_template}} (code, text, created_at, updated_at)'
                . ' VALUES (:code, :text, :now, :now)'
                . ' ON CONFLICT (code) DO UPDATE SET text = EXCLUDED.text, updated_at = EXCLUDED.updated_at',
            )
            ->bindValues([
                ':code' => $code,
                ':text' => $text,
                ':now' => $now,
            ])
            ->execute();
    }
}
