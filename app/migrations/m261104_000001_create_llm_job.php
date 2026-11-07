<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The queue of the background LLM work. One row is one request: the part it was
 * made for, the style it was made in, the text and the marks it started with,
 * and — once the worker has finished — the answer or the reason it failed. The
 * status walks queued → running → done|failed, and the drainer of the cron role
 * is the only one who moves a row along.
 */
final class m261104_000001_create_llm_job extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%llm_job}}', [
            'id' => $this->primaryKey(),
            'status' => $this->string(16)->notNull(),
            'kind' => $this->string(16)->notNull(),
            'part_group_id' => $this->string(32)->notNull(),
            'part_order' => $this->integer()->notNull(),
            'style_name' => $this->string(190)->notNull(),
            'source_text' => $this->text()->notNull(),
            'source_formatting' => $this->json()->notNull(),
            'result_text' => $this->text()->null(),
            'result_formatting' => $this->json()->null(),
            'error' => $this->text()->null(),
            'created_at' => $this->dateTime()->notNull(),
            'started_at' => $this->dateTime()->null(),
            'finished_at' => $this->dateTime()->null(),
        ]);
        $this->createIndex('idx_llm_job_status', '{{%llm_job}}', 'status');
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_llm_job_status', '{{%llm_job}}');
        $this->dropTable('{{%llm_job}}');
    }
}
