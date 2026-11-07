<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmChatRepositoryInterface;
use app\shared\Llm\Dto\LlmChatMessageData;
use PDO;
use yii\db\Connection;

/**
 * PostgreSQL storage for the dialog history. All SQL lives here: the layers above
 * hand DTOs over and never a column name.
 *
 * The window is taken newest-first and turned around, which is the only way to
 * ask for the last fifty (or twelve) rows and still read them in the order the
 * conversation happened.
 */
final class LlmChatRepository implements LlmChatRepositoryInterface
{
    private const COLUMNS = 'id, part_group_id, part_order, role, content,'
        . ' replacement_text, replacement_formatting, created_at';

    public function __construct(private readonly Connection $db)
    {
    }

    public function append(LlmChatMessageData $message, string $now): int
    {
        $this->db
            ->createCommand()
            ->insert('{{%llm_chat_message}}', [
                'part_group_id' => $message->partGroupId,
                'part_order' => $message->partOrder,
                'role' => $message->role,
                'content' => $message->content,
                'replacement_text' => $message->replacementText,
                'replacement_formatting' => $message->replacementFormatting === null
                    ? null
                    : $this->decoded($message->replacementFormatting),
                'created_at' => $now,
            ])
            ->execute();

        return (int) $this->db->getLastInsertID('llm_chat_message_id_seq');
    }

    /**
     * @return LlmChatMessageData[]
     */
    public function latest(string $partGroupId, int $partOrder, int $limit): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT ' . self::COLUMNS . ' FROM {{%llm_chat_message}}'
                . ' WHERE part_group_id = :group AND part_order = :order'
                . ' ORDER BY id DESC LIMIT :limit',
            )
            ->bindValue(':group', $partGroupId)
            ->bindValue(':order', $partOrder)
            ->bindValue(':limit', $limit, PDO::PARAM_INT)
            ->queryAll(PDO::FETCH_ASSOC);

        return array_reverse(array_map(
            static fn (array $row): LlmChatMessageData => new LlmChatMessageData(
                (int) $row['id'],
                (string) $row['part_group_id'],
                (int) $row['part_order'],
                (string) $row['role'],
                (string) $row['content'],
                $row['replacement_text'] === null ? null : (string) $row['replacement_text'],
                $row['replacement_formatting'] === null
                    ? null
                    : self::formattingOf($row['replacement_formatting']),
                (string) $row['created_at'],
            ),
            $rows,
        ));
    }

    /**
     * The marks go into the jsonb column the way the queue and the four
     * publications tables put theirs: Yii encodes an array parameter on write.
     * A string that is not a JSON list is stored as the empty list — the same
     * reading the column of a part gives for a part nobody marked.
     *
     * @return array<int, mixed>
     */
    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The marks as the column hands them back: a JSON string from the driver, an
     * already decoded list where the schema let the array through.
     */
    private static function formattingOf(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
