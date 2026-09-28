<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the title column to the four publications tables: the heading of a part,
 * which the channel draws as a bold first line over the text of that part. An
 * empty string means the part has no heading and goes out as it did before the
 * column existed.
 */
final class m260928_000001_add_title_to_publications extends Migration
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
            $this->addColumn($table, 'title', $this->text()->notNull()->defaultValue(''));
        }
    }

    public function safeDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->dropColumn($table, 'title');
        }
    }
}
