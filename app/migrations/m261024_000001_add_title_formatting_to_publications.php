<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the title_formatting jsonb column to the four publications tables: the
 * inline entities the reader set over the heading itself, in the same shape and
 * with the same types as the formatting column, but counted from the beginning
 * of the heading. An empty array means the heading wears the plain bold line the
 * channel has always put over it.
 */
final class m261024_000001_add_title_formatting_to_publications extends Migration
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
            $this->addColumn($table, 'title_formatting', $this->json()->notNull()->defaultValue('[]'));
        }
    }

    public function safeDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->dropColumn($table, 'title_formatting');
        }
    }
}
