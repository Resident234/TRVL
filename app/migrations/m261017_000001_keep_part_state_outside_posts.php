<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The three tables a part of a publication can rest in outside the posts list.
 * None of them held the group id or the place in it, so a part moved to drafts
 * or to the deleted list arrived there ungrouped and came back without its
 * neighbours. All three now carry the group and the place, the two resting
 * tables carry the state of the queue too, and a part that goes through them
 * and back still waits for the part above it instead of slipping into the queue
 * ahead of it. The archive of channel edits keeps the group and the place only:
 * it never stands in a queue.
 */
final class m261017_000001_keep_part_state_outside_posts extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%publications_draft}}', 'part_group_id', $this->string(32)->null());
        $this->addColumn('{{%publications_draft}}', 'part_order', $this->integer()->null());
        $this->addColumn('{{%publications_draft}}', 'part_released', $this->boolean()->notNull()->defaultValue(true));
        $this->addColumn('{{%publications_deleted}}', 'part_group_id', $this->string(32)->null());
        $this->addColumn('{{%publications_deleted}}', 'part_order', $this->integer()->null());
        $this->addColumn('{{%publications_deleted}}', 'part_released', $this->boolean()->notNull()->defaultValue(true));
        $this->addColumn('{{%publications_edited}}', 'part_group_id', $this->string(32)->null());
        $this->addColumn('{{%publications_edited}}', 'part_order', $this->integer()->null());
        $this->createIndex(
            'idx_publications_draft_part_sequence',
            '{{%publications_draft}}',
            ['part_group_id', 'part_order'],
        );
        $this->createIndex(
            'idx_publications_deleted_part_sequence',
            '{{%publications_deleted}}',
            ['part_group_id', 'part_order'],
        );
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_publications_draft_part_sequence', '{{%publications_draft}}');
        $this->dropIndex('idx_publications_deleted_part_sequence', '{{%publications_deleted}}');
        $this->dropColumn('{{%publications_edited}}', 'part_order');
        $this->dropColumn('{{%publications_edited}}', 'part_group_id');
        $this->dropColumn('{{%publications_deleted}}', 'part_released');
        $this->dropColumn('{{%publications_deleted}}', 'part_order');
        $this->dropColumn('{{%publications_deleted}}', 'part_group_id');
        $this->dropColumn('{{%publications_draft}}', 'part_released');
        $this->dropColumn('{{%publications_draft}}', 'part_order');
        $this->dropColumn('{{%publications_draft}}', 'part_group_id');
    }
}
