<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * A model can produce a valid JSON object and still fail a concrete edit. The
 * prober now asks for one replacement as well, so the council can rank models
 * by whether they actually did what a small instruction asked.
 */
final class m261120_000004_add_llm_model_probe_edit_ok extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%llm_model_probe}}', 'edit_ok', $this->boolean()->notNull()->defaultValue(false));
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%llm_model_probe}}', 'edit_ok');
    }
}
