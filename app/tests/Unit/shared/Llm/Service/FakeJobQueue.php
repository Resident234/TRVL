<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Llm\Service;

use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmJobData;

/**
 * The queue of Task 10 written down: rows in an array, the horizon of each sweep
 * kept as the string it was handed, and every method call named in order — which
 * is how a test says the drain locked before it worked and unlocked after it
 * finished, without a database in sight.
 */
final class FakeJobQueue implements LlmJobQueueInterface
{
    /** @var LlmJobData[] */
    public array $queued = [];

    /** @var array<int, array{id: int, text: string, formatting: string}> */
    public array $done = [];

    /** @var array<int, array{id: int, error: string}> */
    public array $failed = [];

    /** @var string[] */
    public array $calls = [];

    /** @var string[] */
    public array $lockCodes = [];

    public bool $lockGranted = true;

    public int $nextId = 1;

    public int $reclaimResult = 7;

    public int $purgeResult = 9;

    public ?string $reclaimedBefore = null;

    public ?string $purgedBefore = null;

    public function enqueue(LlmJobData $job, string $now): int
    {
        $this->calls[] = 'enqueue';

        $id = $this->nextId++;

        $this->queued[] = new LlmJobData(
            $id,
            $job->status,
            $job->kind,
            $job->partGroupId,
            $job->partOrder,
            $job->styleName,
            $job->sourceText,
            $job->sourceFormatting,
        );

        return $id;
    }

    public function find(int $id): ?LlmJobData
    {
        foreach ($this->queued as $job) {
            if ($job->id === $id) {
                return $job;
            }
        }

        return null;
    }

    public function claim(string $kind, string $now): ?LlmJobData
    {
        $this->calls[] = 'claim';

        foreach ($this->queued as $index => $job) {
            if ($job->kind !== $kind) {
                continue;
            }

            unset($this->queued[$index]);

            return $job;
        }

        return null;
    }

    public function markDone(int $id, string $resultText, string $resultFormatting, string $now): void
    {
        $this->calls[] = 'markDone';

        $this->done[] = ['id' => $id, 'text' => $resultText, 'formatting' => $resultFormatting];
    }

    public function markFailed(int $id, string $error, string $now): void
    {
        $this->calls[] = 'markFailed';

        $this->failed[] = ['id' => $id, 'error' => $error];
    }

    public function reclaimStale(string $startedBefore): int
    {
        $this->calls[] = 'reclaimStale';

        $this->reclaimedBefore = $startedBefore;

        return $this->reclaimResult;
    }

    public function purgeFinished(string $finishedBefore): int
    {
        $this->calls[] = 'purgeFinished';

        $this->purgedBefore = $finishedBefore;

        return $this->purgeResult;
    }

    public function acquireLock(string $code): bool
    {
        $this->calls[] = 'acquireLock';

        $this->lockCodes[] = $code;

        return $this->lockGranted;
    }

    public function releaseLock(string $code): void
    {
        $this->calls[] = 'releaseLock';

        $this->lockCodes[] = $code;
    }
}
