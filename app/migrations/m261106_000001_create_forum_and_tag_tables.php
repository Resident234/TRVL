<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates the forum directory and the tag model: forum, tag, forum_tag.
 *
 * forum holds the sections of forum.awd.ru the tag pass reads titles from,
 * tag holds the normalized tag names, and forum_tag links the two.
 */
final class m261106_000001_create_forum_and_tag_tables extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%forum}}', [
            'id' => $this->integer()->notNull(),
            'source_url' => $this->string(1000)->notNull(),
            'title' => $this->string(1000)->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY ([[id]])',
        ]);

        $this->createTable('{{%tag}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
        ]);
        $this->createIndex('uq_tag_name', '{{%tag}}', 'name', true);

        $this->createTable('{{%forum_tag}}', [
            'forum_id' => $this->integer()->notNull(),
            'tag_id' => $this->integer()->notNull(),
            'created_at' => $this->dateTime()->notNull(),
            'PRIMARY KEY ([[forum_id]], [[tag_id]])',
        ]);
        $this->createIndex('idx_forum_tag_tag_id', '{{%forum_tag}}', 'tag_id');
        $this->addForeignKey(
            'fk_forum_tag_forum',
            '{{%forum_tag}}',
            'forum_id',
            '{{%forum}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk_forum_tag_tag',
            '{{%forum_tag}}',
            'tag_id',
            '{{%tag}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $now = gmdate('Y-m-d H:i:s');
        $this->insert('{{%parser_config}}', [
            'code' => 'awd_forum_sections',
            'base_url' => 'https://forum.awd.ru/viewforum.php?f=',
            't_from' => 1,
            't_to' => 2000,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function safeDown(): void
    {
        $this->delete('{{%parser_config}}', ['code' => 'awd_forum_sections']);

        $this->dropForeignKey('fk_forum_tag_tag', '{{%forum_tag}}');
        $this->dropForeignKey('fk_forum_tag_forum', '{{%forum_tag}}');
        $this->dropIndex('idx_forum_tag_tag_id', '{{%forum_tag}}');
        $this->dropTable('{{%forum_tag}}');

        $this->dropIndex('uq_tag_name', '{{%tag}}');
        $this->dropTable('{{%tag}}');

        $this->dropTable('{{%forum}}');
    }
}
