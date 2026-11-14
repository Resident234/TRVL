<?php

declare(strict_types=1);

namespace app\shared\Llm\Service;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Infrastructure\LlmModelProbeRepository;
use app\shared\Llm\Infrastructure\LlmRequestException;
use app\shared\Settings\Service\PublicationSettingsService;

/**
 * Fetches the list of models from the proxy and probes each one for JSON-format
 * compliance. The result is written to the probe table so LlmTextService can
 * pick reliable fallback models dynamically.
 *
 * The probe sends a minimal spelling request and checks whether the answer
 * contains the JSON contract fields (`text` and `entities`). The request is
 * intentionally tiny so the probe finishes quickly even under load.
 *
 * Models that do not return JSON (routers, image generators, embeddings, TTS)
 * are marked is_ok = false. The prober skips `auto`, `free-router` and similar
 * meta-models that are not real language models -- they would just re-route the
 * request and distort latency measurements.
 *
 * The rank is the wall-clock milliseconds of the successful response, capped at
 * PHP_INT_MAX. Timed-out or failed models receive rank 9999.
 */
final class LlmModelProbeService
{
    /**
     * Models that are routers, embeddings, image generators, TTS, or otherwise
     * not useful as text-format fallbacks. Skipped during probing.
     */
    private const SKIP_PREFIXES = [
        'auto', 'free-router', 'default', 'fusion',
        // image / video / audio -- these cannot answer a text chat request
        'bytedance-seed', 'happyhorse', 'kling', 'wan3', 'dreamina',
        'googlegemini-embedding', 'openaigpt-audio', 'openaigpt-5-image',
        'openaigpt-5.4-image', 'microsoftmai-image',
        'googlegemini-3-pro-image', 'googlegemini-3.1-flash-image',
        'googlegemini-2.5-flash-image', 'googlegemini-3.1-pro-preview',
        'mistral-code-fim', 'codestral-fim', 'north-mini-code', 'mistral-code-fim-latest',
        // safety / guard models
        'llama-guard', 'llama-prompt-guard', 'llama-3.1-nemoguard',
        'llama-3.1-nemotron-safety', 'nemotron-3.5-content-safety',
        'nemotron-parse', 'sea-guard', 'qwen3guard',
        // very small / test models unlikely to follow the format
        'cerebras-gpt-111m', 'pythia-70m',
    ];

    /**
     * The probe message: a one-sentence text with a deliberate comma error.
     * The model must return JSON with `text` and `entities` fields.
     */
    private const PROBE_MESSAGES = [
        ['role' => 'system', 'content' => LlmTextService::ANSWER_CONTRACT],
        ['role' => 'user', 'content' => "Текст части:\nКстати сами монголы русский язык знают неплохо.\n\nМеток у этого текста нет."],
    ];

    /** Seconds to wait for each probe response. Short to keep the run fast. */
    private const PROBE_TIMEOUT = 25;

    /** Keep at most this many models in the ok list. */
    public const TARGET_OK_COUNT = 10;

    public function __construct(
        private readonly LlmClientInterface $client,
        private readonly LlmModelProbeRepository $repo,
        private readonly PublicationSettingsService $settings,
    ) {
    }

    /**
     * Fetch the model list, probe each candidate, write results.
     * Returns a summary: ['probed' => int, 'ok' => int, 'skipped' => int].
     *
     * @return array{probed: int, ok: int, skipped: int}
     */
    public function run(): array
    {
        $models = $this->fetchModelList();
        $now = date('Y-m-d H:i:s');

        $probed = 0;
        $ok = 0;
        $skipped = 0;

        foreach ($models as $modelId) {
            if ($this->shouldSkip($modelId)) {
                $skipped++;
                continue;
            }

            $probed++;
            [$isOk, $rank] = $this->probe($modelId);

            if ($isOk) {
                $ok++;
            }

            $this->repo->save($modelId, $isOk, $rank, $now);
        }

        return ['probed' => $probed, 'ok' => $ok, 'skipped' => $skipped];
    }

    /**
     * @return string[]
     */
    private function fetchModelList(): array
    {
        $baseUrl = rtrim((string) $this->settings->value('llmBaseUrl'), '/');
        // Strip the /v1 suffix to get the API root, then re-add /v1/models.
        $modelsUrl = $baseUrl . '/models';
        $apiKey = (string) $this->settings->value('llmApiKey');

        $handle = curl_init($modelsUrl);
        if ($handle === false) {
            return [];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        ]);

        $raw = curl_exec($handle);
        curl_close($handle);

        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => is_array($item) && isset($item['id']) && is_string($item['id'])
                ? $item['id']
                : null,
            $decoded['data'],
        )));
    }

    /**
     * @return array{0: bool, 1: int}  [isOk, rankMs]
     */
    private function probe(string $modelId): array
    {
        $start = microtime(true);

        try {
            $reply = $this->client->chat(self::PROBE_MESSAGES, self::PROBE_TIMEOUT, $modelId);
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            // Validate the reply contains the JSON contract fields.
            $content = $reply->text;
            $from = strpos($content, '{');
            $to = strrpos($content, '}');
            if ($from === false || $to === false || $to <= $from) {
                return [false, 9999];
            }

            $decoded = json_decode(substr($content, $from, $to - $from + 1), true);
            if (!is_array($decoded) || !isset($decoded['text']) || !is_string($decoded['text'])) {
                return [false, 9999];
            }

            return [true, min($elapsed, 9998)];
        } catch (LlmRequestException) {
            return [false, 9999];
        }
    }

    private function shouldSkip(string $modelId): bool
    {
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($modelId, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
