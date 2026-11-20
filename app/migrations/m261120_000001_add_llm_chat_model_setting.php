<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The dialog of the AI gets its own model setting. The form of the settings
 * page is drawn from SCHEMA, so the field appears there on its own; this row
 * only gives existing installations the value they would have on a fresh one.
 */
final class m261120_000001_add_llm_chat_model_setting extends Migration
{
    public function safeUp(): void
    {
        $this->insert('{{%publications_setting}}', [
            'code' => 'llmModelChat',
            'value' => '',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function safeDown(): void
    {
        $this->delete('{{%publications_setting}}', ['code' => 'llmModelChat']);
    }
}
