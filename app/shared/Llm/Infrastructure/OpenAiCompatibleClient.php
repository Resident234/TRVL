<?php

declare(strict_types=1);

namespace app\shared\Llm\Infrastructure;

use app\shared\Llm\Contract\LlmClientInterface;
use app\shared\Llm\Dto\LlmReply;
use app\shared\Settings\Service\PublicationSettingsService;

/**
 * cURL transport to the OpenAI-compatible proxy the settings point at.
 *
 * The retries, the statuses a retry answers to and the growing sleep are the
 * ones ForumHttpClient already proved against a live server; three things are
 * this client's own: the JSON body, the Authorization header, and a deadline
 * that keeps every attempt together inside the seconds the caller handed over.
 * The deadline is what protects the page — `fastcgi_read_timeout` is 120 s and
 * a second attempt started after the budget is spent would only trade an
 * answer for a gateway error.
 *
 * A refused key (401, 403) is never retried: the proxy says no to the same key
 * on every attempt, and the person at the form waits for nothing.
 *
 * The address, the model and the key are read from the settings on every call,
 * so a change made on the settings page takes effect without rebuilding the
 * singleton. The key travels in a header and nowhere else: it is not written
 * into an exception message, a log or a stored row.
 */
final class OpenAiCompatibleClient implements LlmClientInterface
{
    private const BANNED_STATUS = [429, 500, 502, 503, 504];

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

    public function chat(array $messages, int $timeout): LlmReply
    {
        $baseUrl = (string) $this->settings->value('llmBaseUrl');
        $apiKey = (string) $this->settings->value('llmApiKey');
        $model = (string) $this->settings->value('llmModel');

        if ($baseUrl === '' || $apiKey === '') {
            throw new LlmRequestException('LLM не настроен: адрес или ключ не заданы.');
        }

        $url = self::chatCompletionsUrl($baseUrl);
        $body = (string) json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);
        $deadline = microtime(true) + $timeout;

        $attempt = 0;
        while (true) {
            $attempt++;

            $remaining = (int) ceil($deadline - microtime(true));
            if ($remaining < 1) {
                throw new LlmRequestException(sprintf(
                    'На ответ прокси отведённых %d секунд не хватило.',
                    $timeout,
                ));
            }

            [$status, $statusText, $raw] = $this->request($url, $body, $apiKey, $remaining);

            if ($status >= 200 && $status < 300 && $raw !== '') {
                $decoded = json_decode($raw, true);
                $reply = is_array($decoded) ? LlmReply::fromApi($decoded) : null;
                if ($reply !== null) {
                    return $reply;
                }

                throw new LlmRequestException($this->unreadable($raw));
            }

            if (in_array($status, self::REFUSED_STATUS, true)) {
                throw new LlmRequestException(sprintf('Прокси не принял ключ: %s.', $statusText));
            }

            $retryable = $status === 0 || in_array($status, self::BANNED_STATUS, true);
            if ($attempt > $this->retries || !$retryable) {
                throw new LlmRequestException(sprintf('LLM не ответил: %s.', $statusText));
            }

            usleep($this->delayMicroseconds * $attempt);
        }
    }

    /**
     * Single POST. Status and its line are picked up in the header function the
     * same way ForumHttpClient does it; a cURL failure keeps the transport
     * sentence, because that is the only reason the caller gets.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function request(string $url, string $body, string $apiKey, int $timeout): array
    {
        $status = 0;
        $statusText = '';
        $response = '';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new LlmRequestException('cURL не инициализирован для вызова прокси.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$status, &$statusText): int {
                $length = strlen($header);
                $trimmed = trim($header);
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $trimmed, $m) === 1) {
                    $status = (int) $m[1];
                    $statusText = $trimmed;
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

        return [$status, $statusText, (string) $raw];
    }

    /**
     * The proxy answered 2xx with something that carries no message — usually a
     * reasoning dump instead of the JSON the contract asks for. The first signs
     * are quoted, the body is not: a payload of a stylization run is hundreds of
     * thousands of characters, and none of them belong in a sentence shown to a
     * person.
     */
    private function unreadable(string $raw): string
    {
        return sprintf(
            'Прокси ответил непонятно: %.200s',
            str_replace("\n", ' ', trim($raw)),
        );
    }
}
