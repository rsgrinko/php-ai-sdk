<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Http;
use AiSdk\HttpRequest;
use AiSdk\Tool;

/**
 * HTTP-запрос любого метода: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS.
 *
 * Умеет query-параметры, JSON/form-тело, свои заголовки и Basic/Bearer
 * авторизацию. Креденшелы можно задать в конструкторе по хосту (тогда модель
 * их не видит) или передать аргументом auth.
 *
 * По умолчанию запросы во внутреннюю сеть запрещены (SSRF): localhost,
 * 10.x, 172.16–31.x, 192.168.x, 169.254.x, CGNAT. Нужен доступ к своим API —
 * создайте инструмент с allowPrivate: true.
 */
final class HttpRequestTool implements Tool
{
    private const MAX_REDIRECTS = 3;
    private const TIMEOUT = 10.0;
    private const MAX_BYTES = 262144;
    private const MAX_BODY = 65536;

    /** @var array<string, array<string, string>> креды по хосту (в нижнем регистре) */
    private readonly array $auth;

    /**
     * @param array<string, array<string, string>> $auth креды по хосту:
     *        ['api.example.com' => ['user' => 'bot', 'pass' => '***']]
     *        или ['api.example.com' => ['type' => 'bearer', 'token' => '***']]
     */
    public function __construct(
        array $auth = [],
        private readonly bool $allowPrivate = false,
        private readonly int $defaultChars = 4000,
    ) {
        $normalized = [];

        foreach ($auth as $host => $credentials) {
            $normalized[strtolower((string) $host)] = $credentials;
        }

        $this->auth = $normalized;
    }

    public function name(): string
    {
        return 'http_request';
    }

    public function description(): string
    {
        return 'Выполнить HTTP-запрос: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS. '
            . 'query добавляется к URL, body-объект уходит как JSON (или form при content_type), '
            . 'auth — Basic (user/pass) или Bearer (token). '
            . 'По умолчанию только публичные адреса.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'Полный адрес, например https://api.example.com/orders.'],
                'method' => [
                    'type' => 'string',
                    'enum' => HttpRequest::METHODS,
                    'description' => 'HTTP-метод, по умолчанию GET.',
                ],
                'query' => [
                    'type' => 'object',
                    'description' => 'Параметры строки запроса, например {"page": 2}.',
                ],
                'body' => [
                    'type' => ['object', 'string'],
                    'description' => 'Тело запроса: объект уйдёт как JSON или form, строка — как есть.',
                ],
                'content_type' => [
                    'type' => 'string',
                    'enum' => ['json', 'form', 'text'],
                    'description' => 'Как кодировать body при отправке. По умолчанию json.',
                ],
                'headers' => [
                    'type' => 'object',
                    'description' => 'Дополнительные заголовки, например {"X-Trace": "abc"}.',
                ],
                'auth' => [
                    'type' => 'object',
                    'description' => 'Basic: {"user": "...", "pass": "..."} или Bearer: {"type": "bearer", "token": "..."}.',
                ],
                'max_chars' => [
                    'type' => 'integer',
                    'description' => 'Сколько символов ответа вернуть, по умолчанию ' . $this->defaultChars . '.',
                ],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $rawUrl = trim((string) ($args['url'] ?? ''));

        if ($rawUrl === '') {
            return 'Не указан URL.';
        }

        $maxChars = (int) ($args['max_chars'] ?? $this->defaultChars);
        $maxChars = max(200, min($maxChars, 20000));

        $body = $args['body'] ?? null;

        if (is_string($body) && strlen($body) > self::MAX_BODY) {
            return 'Тело запроса слишком большое (максимум ' . self::MAX_BODY . ' байт).';
        }

        try {
            $request = HttpRequest::build(
                method: (string) ($args['method'] ?? 'GET'),
                url: $rawUrl,
                query: is_array($args['query'] ?? null) ? $args['query'] : [],
                body: $body,
                contentType: (string) ($args['content_type'] ?? 'json'),
                headers: is_array($args['headers'] ?? null) ? $args['headers'] : [],
                auth: $this->resolveAuth($rawUrl, $args['auth'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        if (strlen((string) $request->body) > self::MAX_BODY) {
            return 'Тело запроса слишком большое (максимум ' . self::MAX_BODY . ' байт).';
        }

        try {
            $url = $request->url;
            $response = null;

            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $this->assertAllowed($url);

                $response = Http::request(
                    $url,
                    self::TIMEOUT,
                    false,
                    self::MAX_BYTES,
                    $request->method,
                    $request->headers,
                    $request->body,
                );

                $isRedirect = in_array($response['status'], [301, 302, 303, 307, 308], true);

                // Тело POST/PUT/DELETE при редиректе терять нельзя — отдаём как есть.
                if (!$isRedirect || $response['location'] === null) {
                    break;
                }

                if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                    return 'Редирект ' . $response['status'] . ' на ' . $response['location']
                        . '. Повтори запрос по этому адресу, если он нужен.';
                }

                $url = $this->normalize($this->absolute($url, $response['location']));
            }
        } catch (\Throwable $e) {
            return 'Ошибка: ' . $e->getMessage();
        }

        if ($response === null) {
            return 'Не удалось получить ответ.';
        }

        return $this->format($request->method, $url, $response, $maxChars);
    }

    /**
     * @param array{status: int, body: string, content_type: string|null, truncated: bool} $response
     */
    private function format(string $method, string $url, array $response, int $maxChars): string
    {
        $head = 'HTTP ' . $method . ' ' . $response['status'] . ' — ' . $url;

        if ($response['content_type'] !== null) {
            $head .= ' [' . $response['content_type'] . ']';
        }

        $text = trim($response['body']);

        if ($text === '') {
            return $head . ' — пустой ответ.';
        }

        $truncated = $response['truncated'] || mb_strlen($text) > $maxChars;
        $text = mb_substr($text, 0, $maxChars);

        return $head . ($truncated ? ' (обрезано)' : '') . ":\n" . $text;
    }

    /**
     * Креды: аргумент инструмента важнее карты из конструктора.
     *
     * @return array<string, string>|null
     */
    private function resolveAuth(string $url, mixed $argAuth): ?array
    {
        if (is_array($argAuth) && $argAuth !== []) {
            return array_map(static fn (mixed $value): string => (string) $value, $argAuth);
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return $this->auth[$host] ?? null;
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('некорректный URL');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('разрешены только http и https');
        }

        if ($this->allowPrivate) {
            return;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_contains($host, 'metadata')) {
            throw new \RuntimeException('запрос к локальному адресу запрещён');
        }

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips[] = $host;
        } else {
            $resolved = @gethostbynamel($host);

            if ($resolved === false || $resolved === []) {
                throw new \RuntimeException('не удалось разрешить хост: ' . $host);
            }

            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \RuntimeException('запрос к приватному адресу запрещён: ' . $host);
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10 (CGNAT) не покрывается флагами PHP.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            $cgnat = ip2long('100.64.0.0');

            if ($long !== false && $cgnat !== false && ($long & 0xFFC00000) === ($cgnat & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $url): string
    {
        $url = trim($url);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }

        return 'https://' . ltrim($url, '/');
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('некорректный адрес редиректа');
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }
}
