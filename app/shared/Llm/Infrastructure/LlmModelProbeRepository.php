<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use yii\db\Connection;

/**
 * Read and write the probe results for every model the proxy exposes.
 *
 * The prober writes one row per model (upsert by model_id); the service reads
 * the ordered list of models whose last probe passed. The table is a cache: if
 * it is empty the caller falls back to the hard-coded list in LlmTextService.
 */
final class LlmModelProbeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Upsert one probe result. Called once per model after the probe finishes.
     *
     * @param int $rank Lower is faster/better; set from response latency.
     */
    public function save(string $modelId, bool $isOk, int $rank, string $now): void
    {
        $this->db
            ->createCommand()
            ->upsert(
                '{{%llm_model_probe}}',
                [
                    'model_id' => $modelId,
                    'is_ok' => $isOk,
                    'probe_rank' => $rank,
                    'probed_at' => $now,
                ],
                [
                    'is_ok' => $isOk,
                    'probe_rank' => $rank,
                    'probed_at' => $now,
                ],
            )
            ->execute();
    }

    /**
     * The ordered list of model IDs whose last probe returned a valid JSON
     * answer, fastest first. Returns at most $limit entries.
     *
     * @return string[]
     */
    public function okModels(int $limit = 20): array
    {
        $rows = $this->db
            ->createCommand(
                'SELECT model_id FROM {{%llm_model_probe}}'
                . ' WHERE is_ok = TRUE'
                . ' ORDER BY probe_rank ASC'
                . ' LIMIT :limit',
                [':limit' => $limit],
            )
            ->queryColumn();

        return array_values(array_map('strval', $rows));
    }

    /**
     * Whether the table has at least one probed row (empty on first deploy).
     */
    public function hasAny(): bool
    {
        return (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM {{%llm_model_probe}}')
            ->queryScalar() > 0;
    }
}
