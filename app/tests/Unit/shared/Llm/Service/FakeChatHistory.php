<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmChatRepositoryInterface;
use app\shared\Llm\Dto\LlmChatMessageData;

/**
 * The history of Task 11 written down: rows in an array, the id each appended row
 * invented, the limit the last read asked for, and the two calls named in the
 * order they came — which is how a test says the controller read the window of
 * the conversation before it wrote the command, so the model never meets the
 * same sentence twice.
 */
final class FakeChatHistory implements LlmChatRepositoryInterface
{
    /** @var LlmChatMessageData[] */
    public array $rows = [];

    /** @var string[] */
    public array $calls = [];

    /** @var string[] */
    public array $appended = [];

    public int $lastLimit = 0;

    public int $nextId = 1;

    public function append(LlmChatMessageData $message, string $now): int
    {
        $this->calls[] = 'append';
        $this->appended[] = $message->role;

        $id = $this->nextId++;

        $this->rows[] = new LlmChatMessageData(
            $id,
            $message->partGroupId,
            $message->partOrder,
            $message->role,
            $message->content,
            $message->replacementText,
            $message->replacementFormatting,
            $now,
        );

        return $id;
    }

    /**
     * @return LlmChatMessageData[]
     */
    public function latest(string $partGroupId, int $partOrder, int $limit): array
    {
        $this->calls[] = 'latest';
        $this->lastLimit = $limit;

        $mine = [];
        foreach ($this->rows as $row) {
            if ($row->partGroupId === $partGroupId && $row->partOrder === $partOrder) {
                $mine[] = $row;
            }
        }

        return array_slice($mine, -$limit);
    }
}
