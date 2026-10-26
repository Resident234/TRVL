<?php

declare(strict_types=1);

use app\shared\Telegram\Dto\MessageEntities;
use yii\db\Migration;

/**
 * Writes a bold line over the whole heading into the title_formatting of every
 * record that has a heading and no marks of its own.
 *
 * Until this revision the bold came from the composition itself: a heading saved
 * without marks wore it anyway. From now on a heading wears exactly the marks it
 * was saved with, and those marks the four boxes of the heading block stamp over
 * its line before the form goes out — so a reader who leaves all four off sends a
 * plain heading. Records written while the composition did the stamping carry no
 * marks of their own, and without this fill they would go out plain too.
 *
 * The length of the line is counted by the same MessageEntities::utf16Length that
 * measures every span the channel gets, so the fill cannot drift from the code
 * that reads it back.
 */
final class m261025_000001_stamp_bold_on_headings_without_marks extends Migration
{
    private const TABLES = [
        '{{%publications_post}}',
        '{{%publications_draft}}',
        '{{%publications_edited}}',
        '{{%publications_deleted}}',
    ];

    private const WITHOUT_MARKS = "title <> '' AND title_formatting = '[]'::jsonb";

    public function safeUp(): void
    {
        foreach (self::TABLES as $table) {
            $rows = $this->db
                ->createCommand("SELECT id, title FROM {$table} WHERE " . self::WITHOUT_MARKS)
                ->queryAll();

            foreach ($rows as $row) {
                $this->db->createCommand()->update(
                    $table,
                    ['title_formatting' => self::boldLine((string) $row['title'])],
                    ['id' => (int) $row['id']],
                )->execute();
            }
        }
    }

    public function safeDown(): void
    {
        // The fill has no value to take back: it added a mark a heading wore by the
        // rule of the composition, and the row cannot say which of its marks came
        // from there and which the reader set himself.
    }

    private static function boldLine(string $title): string
    {
        return json_encode([[
            'type' => 'bold',
            'offset' => 0,
            'length' => MessageEntities::utf16Length($title),
        ]], JSON_THROW_ON_ERROR);
    }
}
