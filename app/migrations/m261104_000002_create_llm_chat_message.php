<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The conversation of the free dialog, one row per reply. The pair the row is
 * keyed by is the pair a part of a publication already carries outside the
 * posts list — the group the parts were split into and the place in it — so a
 * part that moves to drafts or to the deleted list keeps the conversation that
 * was had about it.
 *
 * An assistant row carries the replacement it offered next to its prose: the
 * text and the marks «Принять» would put into the field. A user row carries the
 * command as it was typed.
 */
final class m261104_000002_create_llm_chat_message extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%llm_chat_message}}', [
            'id' => $this->primaryKey(),
            'part_group_id' => $this->string(32)->notNull(),
            'part_order' => $this->integer()->notNull(),
            'role' => $this->string(16)->notNull(),
            'content' => $this->text()->notNull(),
            'replacement_text' => $this->text()->null(),
            'replacement_formatting' => $this->json()->null(),
            'created_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex(
            'idx_llm_chat_message_part',
            '{{%llm_chat_message}}',
            ['part_group_id', 'part_order', 'id'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_llm_chat_message_part', '{{%llm_chat_message}}');
        $this->dropTable('{{%llm_chat_message}}');
    }
}
