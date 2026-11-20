<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The dialog model takes the same `auto` default as the common LLM model. An
 * empty string cannot be submitted by the settings form, so an installation
 * that already got the earlier row is brought to the default of the schema.
 */
final class m261120_000003_set_llm_chat_model_default extends Migration
{
    public function safeUp(): void
    {
        $this->update(
            '{{%publications_setting}}',
            ['value' => 'auto'],
            ['code' => 'llmModelChat', 'value' => ''],
        );
    }

    public function safeDown(): void
    {
        $this->update(
            '{{%publications_setting}}',
            ['value' => ''],
            ['code' => 'llmModelChat', 'value' => 'auto'],
        );
    }
}
