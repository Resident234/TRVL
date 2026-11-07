<?php

declare(strict_types=1);

namespace app\shared\Llm\Contract;

use app\shared\Llm\Dto\LlmJobData;

/**
 * The queue of background LLM work, seen as the four things its users do: put a
 * row in, read one back, walk a row along the queue, and keep the queue from
 * being walked by two workers at once.
 *
 * Both horizon numbers are computed by the caller, not here: the reclaim needs
 * twice the timeout of the setting and the purge needs three days, and a
 * repository that compared its own clock would hide both of those decisions.
 */
interface LlmJobQueueInterface
{
    public function enqueue(LlmJobData $job, string $now): int;

    public function find(int $id): ?LlmJobData;

    public function claim(string $kind, string $now): ?LlmJobData;

    public function markDone(int $id, string $resultText, string $resultFormatting, string $now): void;

    public function markFailed(int $id, string $error, string $now): void;

    /**
     * @return int how many abandoned rows went back to the queue
     */
    public function reclaimStale(string $startedBefore): int;

    /**
     * @return int how many finished rows were deleted
     */
    public function purgeFinished(string $finishedBefore): int;

    public function acquireLock(string $code): bool;

    public function releaseLock(string $code): void;
}
