<?php

declare(strict_types=1);

use yii\db\Migration;

final class m261010_000001_add_publication_part_sequence extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%publications_post}}', 'part_group_id', $this->string(32)->null());
        $this->addColumn('{{%publications_post}}', 'part_order', $this->integer()->null());
        $this->addColumn('{{%publications_post}}', 'part_released', $this->boolean()->notNull()->defaultValue(true));
        $this->createIndex('idx_publications_post_part_sequence', '{{%publications_post}}', ['part_group_id', 'part_order']);
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_publications_post_part_sequence', '{{%publications_post}}');
        $this->dropColumn('{{%publications_post}}', 'part_released');
        $this->dropColumn('{{%publications_post}}', 'part_order');
        $this->dropColumn('{{%publications_post}}', 'part_group_id');
    }
}
