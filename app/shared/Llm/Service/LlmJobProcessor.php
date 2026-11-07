<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmJobQueueInterface;
use app\shared\Llm\Dto\LlmJobData;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Llm\Infrastructure\StyleSampleUnavailableException;
use app\shared\Settings\Service\PublicationSettingsService;

/**
 * One cron step of the background queue: the jobs the form put in are read,
 * sent through the two calls of Task 8, and answered back into their rows.
 *
 * The step is a minute and a stylization is longer, so the next tick finds the
 * lock held and leaves empty-handed — the same session-level advisory lock the
 * forum scans take. It is asked for before a row is touched and released in a
 * finally block, so a refused job cannot hold the queue shut.
 *
 * A row is stylized in the style named by the row, not in the style active now:
 * the writer is free to move the choice on the settings page while the job
 * waits, and the row already says what was asked for.
 */
final class LlmJobProcessor
{
    public const LOCK_CODE = 'llm_job';

    public const PURGE_AFTER_DAYS = 3;

    public function __construct(
        private readonly LlmJobQueueInterface $queue,
        private readonly LlmTextService $text,
        private readonly PublicationSettingsService $settings,
    ) {
    }

    /**
     * @return array{locked: bool, reclaimed: int, purged: int, processed: int, done: int, failed: int}
     */
    public function drain(): array
    {
        if (!$this->queue->acquireLock(self::LOCK_CODE)) {
            return [
                'locked' => true,
                'reclaimed' => 0,
                'purged' => 0,
                'processed' => 0,
                'done' => 0,
                'failed' => 0,
            ];
        }

        try {
            $reclaimed = $this->queue->reclaimStale($this->staleHorizon());
            $purged = $this->queue->purgeFinished($this->purgeHorizon());

            $processed = 0;
            $done = 0;
            $failed = 0;

            while (true) {
                $job = $this->queue->claim(LlmJobData::KIND_STYLE, $this->now());
                if ($job === null) {
                    break;
                }

                $processed++;

                try {
                    $answer = $this->text->stylizeIn($job->styleName, $job->sourceText, $job->sourceFormatting);
                    $this->queue->markDone($job->id, $answer->text, $answer->formattingJson(), $this->now());
                    $done++;
                } catch (LlmRequestException | StyleSampleUnavailableException $e) {
                    $this->queue->markFailed($job->id, $e->getMessage(), $this->now());
                    $failed++;
                }
            }

            return [
                'locked' => false,
                'reclaimed' => $reclaimed,
                'purged' => $purged,
                'processed' => $processed,
                'done' => $done,
                'failed' => $failed,
            ];
        } finally {
            $this->queue->releaseLock(self::LOCK_CODE);
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * A row still running after twice the timeout of its own call was left by a
     * container that died mid-call — the call could not have outlived that much,
     * so the row goes back to the queue and the next step does it again.
     */
    private function staleHorizon(): string
    {
        $seconds = 2 * $this->settings->intValue('llmTimeoutSeconds');

        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /**
     * The answers of finished jobs are read by the form for three days and then
     * they are only weight in the table; the same step sweeps them out, so no
     * separate cron line exists for the cleanup.
     */
    private function purgeHorizon(): string
    {
        return gmdate('Y-m-d H:i:s', time() - self::PURGE_AFTER_DAYS * 86400);
    }
}
