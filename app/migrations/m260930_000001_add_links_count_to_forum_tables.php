<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the links_count column to the forum topic and post tables: how many
 * addresses the text of a record carries. The parser writes the number itself
 * (ForumRepository::saveTopic() / savePost()), so the column is a plain integer
 * and not something the database derives: the filter of the publications page
 * then reads an integer of the row instead of scanning the text of every post
 * of every topic it looks at.
 *
 * This migration only counts the text the tables already hold.
 */
final class m260930_000001_add_links_count_to_forum_tables extends Migration
{
    public function safeUp(): void
    {
        foreach (['{{%topic}}', '{{%post}}'] as $table) {
            $this->addColumn($table, 'links_count', $this->integer()->notNull()->defaultValue(0));
            $this->execute("UPDATE $table SET links_count = regexp_count(content_text, 'https?://')");
        }
    }

    public function safeDown(): void
    {
        foreach (['{{%topic}}', '{{%post}}'] as $table) {
            $this->dropColumn($table, 'links_count');
        }
    }
}
