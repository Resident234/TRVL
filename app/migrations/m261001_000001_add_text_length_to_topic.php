<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the text_length column to the forum topic table: how many characters the
 * text of a topic counts. The parser writes the number itself
 * (ForumRepository::saveTopic()), so the column is a plain integer and not
 * something the database derives: the sort of the publications page orders the
 * topics by an integer of the row instead of counting the characters of every
 * topic text it sorts through, which over the whole table costs a second and a
 * half on every page of the block.
 *
 * This migration only counts the text the table already holds.
 */
final class m261001_000001_add_text_length_to_topic extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%topic}}', 'text_length', $this->integer()->notNull()->defaultValue(0));
        $this->execute('UPDATE {{%topic}} SET text_length = char_length(content_text)');
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%topic}}', 'text_length');
    }
}
