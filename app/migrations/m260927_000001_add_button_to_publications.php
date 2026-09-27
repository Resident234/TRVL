<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the button jsonb column to the four publications tables: the link button
 * of a part — a label and an address, the one button Telegram draws under the
 * message. An empty object means the part goes out without a button, which is
 * what every stored row holds until the form fills the field in.
 */
final class m260927_000001_add_button_to_publications extends Migration
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
            $this->addColumn($table, 'button', $this->json()->notNull()->defaultValue('{}'));
        }
    }

    public function safeDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->dropColumn($table, 'button');
        }
    }
}
