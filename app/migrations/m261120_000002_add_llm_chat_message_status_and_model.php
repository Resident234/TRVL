<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * A reply of the model is an offer until the reader accepts or rejects it, and
 * the model that actually answered is remembered with the row. The first half
 * keeps the dialog from treating old offers as the current text; the second
 * keeps one dialog on one model instead of rotating with every router decision.
 */
final class m261120_000002_add_llm_chat_message_status_and_model extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%llm_chat_message}}', 'status', $this->string(16)->notNull()->defaultValue('pending'));
        $this->addColumn('{{%llm_chat_message}}', 'model', $this->string(255)->null());
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%llm_chat_message}}', 'model');
        $this->dropColumn('{{%llm_chat_message}}', 'status');
    }
}
