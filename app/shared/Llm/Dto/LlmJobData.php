<?php

declare(strict_types=1);

namespace app\shared\Llm\Dto;

/**
 * One row of the background queue: what was asked for, what came back, and where
 * the row stands on its way through the queue. The same shape serves the request
 * to put a row in — where the answer half is empty — and the row read back out.
 *
 * The two formatting fields stay JSON strings, the way the hidden field of the
 * form and the jsonb column both hold them; MessageEntities reads that string.
 */
final class LlmJobData
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const KIND_STYLE = 'style';

    public function __construct(
        public readonly int $id = 0,
        public readonly string $status = self::QUEUED,
        public readonly string $kind = self::KIND_STYLE,
        public readonly string $partGroupId = '',
        public readonly int $partOrder = 0,
        public readonly string $styleName = '',
        public readonly string $sourceText = '',
        public readonly string $sourceFormatting = '[]',
        public readonly ?string $resultText = null,
        public readonly ?string $resultFormatting = null,
        public readonly ?string $error = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
    ) {
    }
}
