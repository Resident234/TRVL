<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

use app\shared\Llm\Dto\LlmChatMessageData;

/**
 * The conversation of the free dialog: a reply goes in, the window of the replies
 * that are still there comes back.
 *
 * The order the window comes back in is the order of the block on the screen and
 * the order the model is asked to read — oldest first. The two numbers this
 * revision shows (fifty) and asks for (twelve) are not the business of the
 * storage: the caller names the limit.
 */
interface LlmChatRepositoryInterface
{
    public function append(LlmChatMessageData $message, string $now): int;

    /**
     * @return LlmChatMessageData[] the last `$limit` replies, oldest first
     */
    public function latest(string $partGroupId, int $partOrder, int $limit): array;

    /**
     * Marks one reply of the model accepted or rejected. Nothing changes when
     * the row is gone or belongs to another group.
     */
    public function markAcceptance(int $id, string $partGroupId, string $status): bool;
}
