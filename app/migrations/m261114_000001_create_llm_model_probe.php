<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * A record for each model the proxy exposes, refreshed by the probe cron job.
 * `is_ok` is true when the model returned a valid JSON answer to the format
 * contract on its last probe; false when it answered with prose, did not parse,
 * or timed out. Models with is_ok = true are handed to LlmTextService as the
 * ordered fallback list when `auto` returns a response that does not parse.
 *
 * `probe_rank` is set by the prober from the response latency so the fastest
 * reliable models are tried first.
 */
final class m261114_000001_create_llm_model_probe extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%llm_model_probe}}', [
            'model_id' => $this->string(190)->notNull(),
            'is_ok' => $this->boolean()->notNull()->defaultValue(false),
            'probe_rank' => $this->integer()->notNull()->defaultValue(9999),
            'probed_at' => $this->dateTime()->notNull(),
        ]);

        $this->addPrimaryKey('pk_llm_model_probe', '{{%llm_model_probe}}', 'model_id');
        $this->createIndex('idx_llm_model_probe_ok_rank', '{{%llm_model_probe}}', ['is_ok', 'probe_rank']);
    }

    public function safeDown(): void
    {
        $this->dropIndex('idx_llm_model_probe_ok_rank', '{{%llm_model_probe}}');
        $this->dropTable('{{%llm_model_probe}}');
    }
}
