<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * The value column of the settings table grows to `text`. The default prompt of the
 * style transfer is longer than the 255 characters the table was born with, so the
 * first save of the LLM block would have been refused by the database.
 */
final class m261104_000000_widen_publications_setting_value extends Migration
{
    public function safeUp(): void
    {
        $this->alterColumn('{{%publications_setting}}', 'value', $this->text()->notNull());
    }

    public function safeDown(): void
    {
        $this->alterColumn('{{%publications_setting}}', 'value', $this->string(255)->notNull());
    }
}
