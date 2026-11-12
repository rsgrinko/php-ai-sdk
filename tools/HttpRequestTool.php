<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\HttpTransport;
use AiSdk\HttpRequest;
use AiSdk\Tools\Support\UrlGuard;
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
    private readonly UrlGuard $guard;

    /**
     * @param array<string, array<string, string>> $auth креды по хосту:
     *        ['api.example.com' => ['user' => 'bot', 'pass' => '***']]
     *        или ['api.example.com' => ['type' => 'bearer', 'token' => '***']]
     */
    public function __construct(
        array $auth = [],
        bool $allowPrivate = false,
        private readonly int $defaultChars = 4000,
    ) {
        $normalized = [];

        foreach ($auth as $host => $credentials) {
            $normalized[strtolower((string) $host)] = $credentials;
        }

        $this->auth = $normalized;
        $this->guard = new UrlGuard($allowPrivate);
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
                $this->guard->assertAllowed($url);

                $response = HttpTransport::request(
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

                $url = HttpRequest::normalizeUrl(HttpRequest::resolveRedirect($url, $response['location']));
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




}


