<?php

declare(strict_types=1);

/**
 * LLM Speed Reader
 *
 * Usage:
 *   php app/utils/llm-speed-reader.php
 *   php app/utils/llm-speed-reader.php --model=gpt-3.5-turbo
 */

const BASE_URL = 'http://localhost:31415'; // change to your proxy address
const API_KEY = 'your-api-key'; // change to your api-key

/**
 * Get the list of models from the proxy.
 */
function getModels(string $baseUrl, string $apiKey): array
{
    $url = rtrim($baseUrl, '/') . '/models';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new \RuntimeException("HTTP error: $httpCode");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        throw new \RuntimeException('Invalid response format');
    }

    return array_column($decoded['data'], 'id');
}

/**
 * Measure response time of a single model.
 *
 * @param string $model
 * @param string $baseUrl
 * @param string $apiKey
 * @return array{milliseconds: int, seconds: float, text: string}
 */
function measureModel(string $model, string $baseUrl, string $apiKey): array
{
    $messages = [
        ['role' => 'user', 'content' => 'Краткий ответ: привет.'],
    ];

    $client = new OpenAiCompatibleClient(
        new PublicationSettingsService(
            \Yii::createObject(\app\shared\Settings\Contract\PublicationSettingsRepositoryInterface::class),
            new \app\shared\Settings\Infrastructure\DirectoryTextStyleCatalog(\app\shared\Settings\Infrastructure\DirectoryTextStyleCatalog::fromEnvironment()),
            '',
        ),
        retries: 1,
        delayMicroseconds: 0,
    );

    $start = microtime(true);

    try {
        $client->chat($messages, 30, $model);
        $elapsed = (int) round((microtime(true) - $start) * 1000);

        return [
            'model' => $model,
            'milliseconds' => $elapsed,
            'seconds' => round($elapsed / 1000, 3),
            'text' => 'OK',
        ];
    } catch (\Exception $e) {
        $elapsed = (int) round((microtime(true) - $start) * 1000);

        return [
            'model' => $model,
            'milliseconds' => $elapsed,
            'seconds' => round($elapsed / 1000, 3),
            'text' => 'ERROR: ' . $e->getMessage(),
        ];
    }
}

/**
 * Main execution.
 */
function main(): void
{
    global $argv;

    $shortOptions = 'm:';
    $longOptions = ['model:'];
    $options = getopt($shortOptions, $longOptions);

    $modelFilter = $options['m'] ?? $options['model'] ?? null;

    echo "LLM Speed Reader\n";
    echo str_repeat('=', 50) . "\n\n";

    $settings = loadSettings();
    if (!$settings['valid']) {
        echo "[ОШИБКА] " . $settings['error'] . "\n";
        exit(1);
    }

    echo "[OK] Подключение к " . $settings['baseUrl'] . "\n";
    echo "[OK] Ключ получен\n\n";

    try {
        $models = getModels($settings['baseUrl'], $settings['apiKey']);
    } catch (\Exception $e) {
        echo "[ОШИБКА] " . $e->getMessage() . "\n";
        exit(1);
    }

    echo "[OK] Найдено моделей: " . count($models) . "\n\n";

    if ($modelFilter) {
        $models = array_filter($models, static fn (string $m): bool => str_contains($m, $modelFilter));
        echo "[INFO] Фильтр: только модели с '$modelFilter'\n";
    }

    echo "Ожидание измерений...\n";
    echo str_repeat('-', 50) . "\n";

    $results = [];
    foreach ($models as $model) {
        echo "Измерение $model ... ";
        $result = measureModel($model, $settings['baseUrl'], $settings['apiKey']);
        $results[] = $result;
        echo "готово ({$result['milliseconds']} мс)\n";
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "ИТОГОВЫЙ ОТЧЕТ\n";
    echo str_repeat('=', 50) . "\n";

    // Сортировка
    usort($results, static fn (array $a, array $b): int => $a['milliseconds'] <=> $b['milliseconds']);

    foreach ($results as $i => $r) {
        echo sprintf(
            "%d. %s\n",
            $i + 1,
            $r['model'],
        );
        echo sprintf(
            "   Время: %d мс (%s с)\n",
            $r['milliseconds'],
            $r['seconds'],
        );
        echo sprintf(
            "   Статус: %s\n",
            $r['text'],
        );
        echo "\n";
    }

    // Рекомендации
    echo str_repeat('-', 50) . "\n";
    echo "РЕКОМЕНДАЦИИ:\n";
    echo str_repeat('-', 50) . "\n";

    $fastest = $results[0] ?? null;
    if ($fastest) {
        echo sprintf(
            "Самая быстрая модель: %s (%d мс)\n",
            $fastest['model'],
            $fastest['milliseconds'],
        );
    }

    echo "\nТаймаут для стилизации = максимальное время ответа + буфер (0.5-1.0 сек)\n";
    echo "Пример: для моделей с 2000-3000 мс → timeout = 3.5-4.0 с\n";
}

/**
 * Load settings from environment/settings.
 */
function loadSettings(): array
{
    $baseUrl = getenv('LLM_BASE_URL') ?: BASE_URL;
    $apiKey = getenv('LLM_API_KEY') ?: API_KEY;

    if ($baseUrl === 'your-api-key') {
        return [
            'valid' => false,
            'error' => 'LLM_BASE_URL или LLM_API_KEY не заданы. '
                . 'Создайте файл .env с переменными окружения.',
        ];
    }

    if ($baseUrl === '' || $apiKey === '') {
        return [
            'valid' => false,
            'error' => 'LLM_BASE_URL и LLM_API_KEY должны быть заданы.',
        ];
    }

    return [
        'valid' => true,
        'baseUrl' => rtrim($baseUrl, '/'),
        'apiKey' => $apiKey,
    ];
}

main();
