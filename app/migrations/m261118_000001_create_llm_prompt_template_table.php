<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Gives the prompt templates a reader saves from the AI dialog a table of
 * their own. The settings table keeps only the tunables and the two prompts
 * the settings page edits; a template is user content, not a tunable. Rows
 * saved while templates still lived in publications_setting are moved over,
 * under the same code, so nothing the reader wrote is lost.
 */
final class m261118_000001_create_llm_prompt_template_table extends Migration
{
    private const OLD_CODE_PREFIX = 'llmPromptTemplate_';

    public function safeUp(): void
    {
        $this->createTable('{{%llm_prompt_template}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(64)->notNull(),
            'text' => $this->text()->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(
            'uq_llm_prompt_template_code',
            '{{%llm_prompt_template}}',
            'code',
            true,
        );

        $this->execute(
            'INSERT INTO {{%llm_prompt_template}} (code, text, created_at, updated_at)'
            . ' SELECT code, value, updated_at, updated_at'
            . ' FROM {{%publications_setting}}'
            . " WHERE code LIKE '" . self::OLD_CODE_PREFIX . "%'",
        );
        $this->execute(
            'DELETE FROM {{%publications_setting}}'
            . " WHERE code LIKE '" . self::OLD_CODE_PREFIX . "%'",
        );
    }

    public function safeDown(): void
    {
        $this->execute(
            'INSERT INTO {{%publications_setting}} (code, value, updated_at)'
            . ' SELECT code, text, updated_at FROM {{%llm_prompt_template}}'
            . ' ON CONFLICT (code) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at',
        );
        $this->dropIndex('uq_llm_prompt_template_code', '{{%llm_prompt_template}}');
        $this->dropTable('{{%llm_prompt_template}}');
    }
}
