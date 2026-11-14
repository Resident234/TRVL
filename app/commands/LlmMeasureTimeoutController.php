<?php

declare(strict_types=1);

namespace app\commands;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Infrastructure\OpenAiCompatibleClient;
use app\shared\Settings\Service\PublicationSettingsService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Measures real response time of LLM models to help pick the right timeout.
 *
 * Usage:
 *   yii llm/measure-timeout --models=10
 */
final class LlmMeasureTimeoutController extends Controller
{
    public ?int $models = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['models']);
    }

    public function actionRun(): int
    {
        $settings = new PublicationSettingsService(
            \Yii::createObject(\app\shared\Settings\Contract\PublicationSettingsRepositoryInterface::class),
            new \app\shared\Settings\Infrastructure\DirectoryTextStyleCatalog(\app\shared\Settings\Infrastructure\DirectoryTextStyleCatalog::fromEnvironment()),
            '',
        );

        $baseUrl = (string) $settings->value('llmBaseUrl');
        $apiKey = (string) $settings->value('llmApiKey');
        $maxModels = $this->models ?? 5;

        if ($baseUrl === '' || $apiKey === '') {
            $this->stdout("LLM не настроен: отсутствует адрес или ключ.\n");

            return ExitCode::INVALID_PARAMETER;
        }

        $this->stdout("Измерение скорости ответа LLM на $baseUrl\n");
        $this->stdout(str_repeat('-', 60) . "\n");

        $handle = curl_init($baseUrl . '/models');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        ]);

        $raw = curl_exec($handle);
        curl_close($handle);

        if (!is_string($raw)) {
            $this->stdout("Не удалось получить список моделей.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            $this->stdout("Некорректный ответ от прокси.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $models = [];
        foreach ($decoded['data'] as $item) {
            if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $models[] = $item['id'];
            }
        }

        if (empty($models)) {
            $this->stdout("Модели не найдены.\n");

            return ExitCode::OK;
        }

        $models = array_slice($models, 0, $maxModels);
        $this->stdout("Тестируем " . count($models) . " моделей: " . implode(', ', $models) . "\n");
        $this->stdout(str_repeat('-', 60) . "\n");

        $results = [];
        foreach ($models as $model) {
            $results = array_merge($results, $this->measureModel($model, $settings, $apiKey, $baseUrl));
        }

        if (empty($results)) {
            $this->stdout("Ни одна модель не ответила.\n");

            return ExitCode::OK;
        }

        // Сортируем по времени ответа (быстрее сверху)
        usort($results, static fn (array $a, array $b): int => $a['timeMs'] <=> $b['timeMs']);

        $this->stdout("\nРезультаты (от быстрейших):\n");
        $this->stdout(str_repeat('-', 60) . "\n");

        foreach ($results as $i => $result) {
            $this->stdout(sprintf(
                "%d. %s\n",
                $i + 1,
                $result['model'],
            ));

            $this->stdout(sprintf(
                "   Время ответа: %d мс (%d.%03d с)\n",
                $result['timeMs'],
                $result['timeMs'] / 1000,
                $result['timeMs'] % 1000,
            ));

            $this->stdout(sprintf(
                "   Ошибки/Ретраи: %d\n",
                $result['errors'],
            ));
        }

        $this->stdout(str_repeat('-', 60) . "\n");

        return ExitCode::OK;
    }

    private function measureModel(string $model, PublicationSettingsService $settings, string $apiKey, string $baseUrl): array
    {
        $messages = [
            ['role' => 'user', 'content' => 'Краткий ответ: привет.'],
        ];

        $client = new OpenAiCompatibleClient($settings, retries: 1, delayMicroseconds: 0);
        $start = microtime(true);

        try {
            $reply = $client->chat($messages, 30, $model);
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            return [
                [
                    'model' => $model,
                    'timeMs' => $elapsed,
                    'errors' => 0,
                    'status' => 'ok',
                ],
            ];
        } catch (\Exception $e) {
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            return [
                [
                    'model' => $model,
                    'timeMs' => $elapsed,
                    'errors' => 1,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
}
