// Основной код
echo "Лабораторный тест скорости LLM\n";
echo str_repeat('=', 70) . "\n";

if (php_sapi_name() !== 'cli') {
    echo "Ошибка: скрипт запускается только из CLI.\n";
    exit(1);
}

$shortOptions = 'm:';
$longOptions = ['models:'];
$options = getopt($shortOptions, $longOptions);
$maxModels = isset($options['m']) ? (int)$options['m'] : 5;

echo "Тестирование с параметром: --models=" . $maxModels . "\n\n";

$settings = loadSettings();
$baseUrl = $settings['baseUrl'];
$apiKey = $settings['apiKey'];

if ($baseUrl === '') {
    echo "Ошибка: llmBaseUrl не задан в настройках.\n";
    exit(1);
}

if ($apiKey === '') {
    echo "Ошибка: llmApiKey не задан в настройках.\n";
    exit(1);
}

echo "Проверка подключения к $baseUrl ...\n";
echo "Если подключение долгое, проверьте сеть или нет ли proxy на пути.\n\n";

echo "Получение списка моделей ...\n";
try {
    $models = getModels($baseUrl, $apiKey, $maxModels);
} catch (\Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Найдено моделей: " . count($models) . "\n";
echo "Тестируем: " . implode(', ', $models) . "\n\n";

echo str_repeat('=', 70) . "\n";
echo "ИЗМЕРЕНИЯ\n";
echo str_repeat('=', 70) . "\n\n";

$results = [];
foreach ($models as $model) {
    echo "Тестирование $model ... ";
    flush();

    $result = measureModel($model, $baseUrl, $apiKey);
    $results[] = $result;

    if ($result['status'] === 'ok') {
        echo "OK ({$result['timeMs']} мс)\n";
    } else {
        echo "ОШИБКА: " . $result['error'] . "\n";
    }
}

printTable($results);

echo "\nВывод: используйте максимальное значение из указанных выше с запасом 20-30%.\n";
echo "Пример: если быстрейшие модели отвечают за 2000-3000 мс, "
    . "возьмите timeout = " . (int)round($maxTime / 1000) . " сек.\n\n";
