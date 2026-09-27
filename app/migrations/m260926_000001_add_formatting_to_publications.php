<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the formatting jsonb column to the four publications tables: the inline
 * entities of the part text (bold, italic, underline, strikethrough, code,
 * text_link) exactly as the Telegram API takes them, offsets in UTF-16 code
 * units. An empty array means plain text, which is what every stored row is
 * until the editor marks something.
 */
final class m260926_000001_add_formatting_to_publications extends Migration
{
    private const TABLES = [
        '{{%publications_post}}',
        '{{%publications_draft}}',
        '{{%publications_edited}}',
        '{{%publications_deleted}}',
    ];

    public function safeUp(): void
    {
        foreach (self::TABLES as $table) {
            $this->addColumn($table, 'formatting', $this->json()->notNull()->defaultValue('[]'));
        }
    }

    public function safeDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->dropColumn($table, 'formatting');
        }
    }
}
