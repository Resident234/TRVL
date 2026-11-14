<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmReply;
use app\shared\Settings\Service\PublicationSettingsService;

/**
 * cURL transport to the OpenAI-compatible proxy the settings point at.
 *
 * Each attempt gets the complete configured timeout. Temporary transport failures,
 * 429 responses and 5xx responses are retried with a short backoff. The API key is
 * used only in the Authorization header and is never included in diagnostics.
 *
 * Every request and response is written to the dedicated `llm.transport` log
 * category. The web and console configurations route that category to
 * app/runtime/logs/llm.log, so a slow provider and a rate limit can be told apart.
 */
final class OpenAiCompatibleClient implements LlmClientInterface
{
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    private const REFUSED_STATUS = [401, 403];

    public function __construct(
        private readonly PublicationSettingsService $settings,
        private readonly int $retries = 2,
        private readonly int $delayMicroseconds = 500000,
    ) {
    }

    public static function chatCompletionsUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/chat/completions';
    }

    public function chat(array $messages, int $timeout, ?string $modelOverride = null): LlmReply
    {
        $baseUrl = (string) $this->settings->value('llmBaseUrl');
        $apiKey = (string) $this->settings->value('llmApiKey');
        $model = $modelOverride ?? (string) $this->settings->value('llmModel');

        if ($baseUrl === '' || $apiKey === '') {
            throw new LlmRequestException('LLM не настроен: адрес или ключ не заданы.');
        }

        $url = self::chatCompletionsUrl($baseUrl);
        $body = (string) json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);
        $attempts = max(1, $this->retries + 1);
        $timeout = max(1, $timeout);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->log('info', 'LLM request started', [
                'url' => $url,
                'model' => $model,
                'attempt' => $attempt,
                'attempts' => $attempts,
                'timeoutSeconds' => $timeout,
                'payload' => $this->decodeForLog($body),
            ]);

            [$status, $statusText, $raw, $retryAfter] = $this->request($url, $body, $apiKey, $timeout);
            $this->log($status >= 200 && $status < 300 ? 'info' : 'warning', 'LLM response received', [
                'url' => $url,
                'model' => $model,
                'attempt' => $attempt,
                'status' => $status,
                'statusLine' => $statusText,
                'retryAfterSeconds' => $retryAfter,
                'body' => $this->bodyForLog($raw),
            ]);

            if ($status >= 200 && $status < 300 && $raw !== '') {
                $decoded = json_decode($raw, true);
                $reply = is_array($decoded) ? LlmReply::fromApi($decoded) : null;
                if ($reply !== null) {
                    $this->log('info', 'LLM response parsed', [
                        'model' => $model,
                        'attempt' => $attempt,
                        'textLength' => mb_strlen($reply->text),
                    ]);

                    return $reply;
                }

                throw new LlmRequestException($this->unreadable($raw), 0, null, $status, false);
            }

            if (in_array($status, self::REFUSED_STATUS, true)) {
                throw new LlmRequestException(
                    sprintf('Прокси не принял ключ: %s.', $statusText ?: 'HTTP ' . $status),
                    0,
                    null,
                    $status,
                    false,
                );
            }

            $retryable = $status === 0 || in_array($status, self::RETRYABLE_STATUS, true);
            if (!$retryable || $attempt >= $attempts) {
                $message = $status === 0
                    ? ($statusText !== '' ? $statusText : 'транспорт не ответил')
                    : ($statusText ?: 'HTTP ' . $status);
                $this->log('error', 'LLM request failed permanently', [
                    'url' => $url,
                    'model' => $model,
                    'attempt' => $attempt,
                    'status' => $status,
                    'statusLine' => $statusText,
                    'body' => $this->bodyForLog($raw),
                ]);

                throw new LlmRequestException(
                    'LLM не ответил: ' . $message . '.',
                    0,
                    null,
                    $status > 0 ? $status : null,
                    $retryable,
                );
            }

            $delay = $this->retryDelay($retryAfter, $attempt);
            $this->log('warning', 'LLM request will be retried', [
                'url' => $url,
                'model' => $model,
                'attempt' => $attempt,
                'status' => $status,
                'delaySeconds' => $delay / 1000000,
            ]);
            usleep($delay);
        }

        throw new LlmRequestException('LLM не ответил: цикл попыток завершился неожиданно.', 0, null, null, true);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: ?int}
     */
    private function request(string $url, string $body, string $apiKey, int $timeout): array
    {
        $status = 0;
        $statusText = '';
        $retryAfter = null;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new LlmRequestException('cURL не инициализирован для вызова прокси.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(20, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$status, &$statusText, &$retryAfter): int {
                $length = strlen($header);
                $trimmed = trim($header);
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $trimmed, $m) === 1) {
                    $status = (int) $m[1];
                    $statusText = $trimmed;
                }
                if (stripos($trimmed, 'Retry-After:') === 0) {
                    $value = trim(substr($trimmed, strlen('Retry-After:')));
                    if (ctype_digit($value)) {
                        $retryAfter = (int) $value;
                    }
                }

                return $length;
            },
        ]);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $statusText = (string) curl_error($handle);
            $raw = '';
        }
        curl_close($handle);

        return [$status, $statusText, (string) $raw, $retryAfter];
    }

    private function retryDelay(?int $retryAfter, int $attempt): int
    {
        $backoff = $this->delayMicroseconds * $attempt;
        if ($retryAfter === null) {
            return $backoff;
        }

        return max($backoff, min(30, $retryAfter) * 1000000);
    }

    private function decodeForLog(string $body): mixed
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : $body;
    }

    private function bodyForLog(string $body): string
    {
        return mb_strlen($body) > 200000 ? mb_substr($body, 0, 200000) . '…[truncated]' : $body;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (!class_exists('Yii')) {
            return;
        }

        $context['timeUtc'] = gmdate('c');
        $context['component'] = 'OpenAiCompatibleClient';
        $record = $message . ' ' . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($level === 'error') {
            \Yii::error($record, 'llm.transport');
        } elseif ($level === 'warning') {
            \Yii::warning($record, 'llm.transport');
        } else {
            \Yii::info($record, 'llm.transport');
        }
    }

    private function unreadable(string $raw): string
    {
        return sprintf(
            'Прокси ответил непонятно: %.200s',
            str_replace("\n", ' ', trim($raw)),
        );
    }
}
