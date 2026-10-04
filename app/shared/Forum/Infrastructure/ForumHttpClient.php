<?php

declare(strict_types=1);

namespace app\shared\Forum\Infrastructure;

use app\shared\Forum\Contract\ForumHttpClientInterface;
use RuntimeException;

/**
 * HTTP adapter for the forum. Throws ForumPageNotFoundException for
 * missing pages so the scan can skip them without failing the run.
 *
 * When forum credentials are set, the client keeps a phpBB session in
 * its own cookie storage and logs in automatically: profiles and some
 * forum sections are visible to authorized users only. A page answering
 * with the "вы должны быть авторизованы" login form triggers a single
 * authentication attempt, then the request is retried with the session.
 *
 * Cookies are tracked in memory through a header function instead of a
 * cURL cookie file: the jar file is unreliable under HTTP/2 (Set-Cookie
 * of the login response is not always persisted before close).
 *
 * The jar is kept per host, because this client does not only read the
 * forum: the publishing queue fetches the pictures of a publication from
 * whatever host they stand on through it. A session of the forum belongs
 * to the forum, and a cookie of another host must not be handed to it
 * either, so a request carries only the cookies the host it goes to gave
 * out itself.
 */
final class ForumHttpClient implements ForumHttpClientInterface
{
    private const BANNED_STATUS = [429, 500, 502, 503, 504];

    private const MAX_REDIRECTS = 5;

    private const LOGIN_MARKER = 'вы должны быть авторизованы';

    /** @var array<string, array<string, string>> host => cookie name => value */
    private array $_cookies = [];

    /** @var int[] */
    private array $bannedStatuses;

    /**
     * The timeouts, the retry count, the backoff base, the redirect budget and
     * the status list a retry answers to all come from parser_config; the
     * defaults here are what the client does when it is built by hand (tests,
     * a table without rows).
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $retries = 3,
        private readonly int $delayMicroseconds = 500000,
        private readonly string $loginUrl = 'https://forum.awd.ru/ucp.php?mode=login',
        private readonly string $loginUsername = '',
        private readonly string $loginPassword = '',
        private readonly int $maxRedirects = self::MAX_REDIRECTS,
        ?array $bannedStatuses = null,
    ) {
        $this->bannedStatuses = $bannedStatuses ?? self::BANNED_STATUS;
    }

    public function get(string $url): string
    {
        $body = $this->sendWithRetries($url);
        if ($this->loginUsername !== '' && str_contains($body, self::LOGIN_MARKER)) {
            $this->login();
            $body = $this->sendWithRetries($url);
        }
        return $body;
    }

    /**
     * GET with retries on 429/5xx. Throws ForumPageNotFoundException
     * for 404 answers and RuntimeException for other failures.
     */
    private function sendWithRetries(string $url): string
    {
        $attempt = 0;
        $status = 0;
        $statusText = '';
        $body = false;
        while (true) {
            $attempt++;
            [$status, $statusText, $body] = $this->request($url);
            if ($status >= 200 && $status < 300 && $body !== false) {
                return $body;
            }
            if ($status === 404) {
                throw new ForumPageNotFoundException('Page does not exist: ' . $url);
            }
            if ($attempt > $this->retries || (!in_array($status, $this->bannedStatuses, true) && $status >= 400)) {
                throw new RuntimeException(sprintf('Forum request failed: %s [%s]', $url, $statusText));
            }
            usleep($this->delayMicroseconds * $attempt);
        }
    }

    /**
     * Authenticates against the phpBB login form: fetches the login page
     * (picking up a fresh anonymous sid cookie), posts the credentials
     * and keeps the session cookies returned by the server.
     */
    private function login(): void
    {
        $loginPage = $this->sendWithRetries($this->loginUrl);
        $sid = '';
        if (preg_match('~name="sid"\s+value="([^"]+)"~u', $loginPage, $m) === 1) {
            $sid = $m[1];
        }

        [$status, $statusText, $body] = $this->request($this->loginUrl, [
            'username' => $this->loginUsername,
            'password' => $this->loginPassword,
            'login' => 'Вход',
            'redirect' => 'index.php',
            'sid' => $sid,
        ]);
        if ($body === false || $status >= 400) {
            throw new RuntimeException(sprintf('Forum login failed: %s [%s]', $this->loginUrl, $statusText));
        }
        if (($this->_cookies[$this->hostOf($this->loginUrl)]['phpbb3_alft2_u'] ?? '1') === '1') {
            throw new RuntimeException('Forum login failed: the session cookie was not granted.');
        }
    }

    /**
     * Single HTTP request. Collects Set-Cookie headers into the cookie storage
     * of the host the request was made to.
     *
     * @param array<string, string>|null $postFields
     * @return array{0: int, 1: string, 2: string|false}
     */
    private function request(string $url, ?array $postFields = null): array
    {
        $status = 0;
        $statusText = '';
        $body = false;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize cURL for: ' . $url);
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TRVL-Parser/1.0)',
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$status, &$statusText, $url): int {
                $length = strlen($header);
                $trimmed = trim($header);
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $trimmed, $m) === 1) {
                    $status = (int)$m[1];
                    $statusText = $trimmed;
                }
                if (preg_match('~^Set-Cookie:\s*([^=]+)=([^;]*)~i', $trimmed, $m) === 1) {
                    $this->keepCookie($url, trim($m[1]), trim($m[2]));
                }
                return $length;
            },
        ];
        $cookieHeader = $this->cookieHeader($url);
        if ($cookieHeader !== '') {
            $options[CURLOPT_COOKIE] = $cookieHeader;
        }
        if ($postFields !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if ($body === false) {
            $statusText = (string)curl_error($handle);
        }
        curl_close($handle);
        return [$status, $statusText, $body];
    }

    /**
     * Files one cookie under the host that answered with it, so the jar of one
     * host never travels to another.
     */
    private function keepCookie(string $url, string $name, string $value): void
    {
        $host = $this->hostOf($url);
        if (!isset($this->_cookies[$host])) {
            $this->_cookies[$host] = [];
        }
        $this->_cookies[$host][$name] = $value;
    }

    /**
     * The host a request belongs to, lower cased, the key its cookies are kept
     * under. An address carrying none keys a jar of its own, which holds
     * nothing: no other host handed a cookie out to it.
     */
    private function hostOf(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * The Cookie header of one host, empty when it never handed a cookie out.
     */
    private function cookieHeader(string $url): string
    {
        $host = $this->hostOf($url);
        if (!isset($this->_cookies[$host])) {
            return '';
        }
        $pairs = [];
        foreach ($this->_cookies[$host] as $name => $value) {
            if ($value !== '') {
                $pairs[] = $name . '=' . $value;
            }
        }
        return implode('; ', $pairs);
    }
}
