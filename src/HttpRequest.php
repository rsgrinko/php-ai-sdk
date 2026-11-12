<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Разобранный HTTP-запрос: метод, URL, заголовки, тело.
 *
 * Собирается из аргументов инструмента и умеет то, что нужно для реальных API:
 * query-параметры, JSON- или form-тело, свои заголовки, Basic/Bearer авторизация.
 */
final class HttpRequest
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** Заголовки, которые нельзя задавать вручную: их ставит cURL. */
    private const FORBIDDEN_HEADERS = [
        'host',
        'content-length',
        'transfer-encoding',
        'connection',
        'expect',
    ];

    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array{type?: string, user?: string, pass?: string, token?: string}|null $auth
     *
     * @throws \InvalidArgumentException
     */
    public static function build(
        string $method,
        string $url,
        array $query = [],
        mixed $body = null,
        string $contentType = 'json',
        array $headers = [],
        ?array $auth = null,
    ): self {
        $method = strtoupper(trim($method));

        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(
                'Метод ' . $method . ' не поддерживается. Доступно: ' . implode(', ', self::METHODS) . '.'
            );
        }

        $url = self::withQuery(self::normalizeUrl($url), $query);
        $normalized = [];

        foreach ($headers as $name => $value) {
            $name = trim((string) $name);

            if ($name === '' || in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)) {
                continue;
            }

            $normalized[$name] = (string) $value;
        }

        [$encoded, $mime] = self::encodeBody($body, $contentType);

        if ($encoded !== null && !self::hasHeader($normalized, 'content-type')) {
            $normalized['Content-Type'] = $mime;
        }

        if (!self::hasHeader($normalized, 'accept')) {
            $normalized['Accept'] = '*/*';
        }

        $authorization = self::authorization($auth);

        if ($authorization !== null) {
            // Свои креденшелы важнее заголовка Authorization из аргументов.
            foreach (array_keys($normalized) as $name) {
                if (strtolower($name) === 'authorization') {
                    unset($normalized[$name]);
                }
            }

            $normalized['Authorization'] = $authorization;
        }

        return new self($method, $url, $normalized, $encoded);
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new \InvalidArgumentException('Не указан URL.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }

        return 'https://' . ltrim($url, '/');
    }

    /**
     * Абсолютный адрес редиректа относительно текущего URL.
     *
     * @throws \RuntimeException если базовый адрес некорректен
     */
    public static function resolveRedirect(string $base, string $location): string
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

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $directory . '/' . $location;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function withQuery(string $url, array $query): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{0: string|null, 1: string} тело и mime-тип
     */
    private static function encodeBody(mixed $body, string $contentType): array
    {
        if ($body === null || $body === '') {
            return [null, self::mime($contentType)];
        }

        if (is_string($body)) {
            return [$body, self::mime($contentType)];
        }

        if (!is_array($body)) {
            throw new \InvalidArgumentException('body должен быть объектом или строкой.');
        }

        if (strtolower($contentType) === 'form') {
            return [http_build_query($body), 'application/x-www-form-urlencoded'];
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \InvalidArgumentException('не удалось сериализовать body в JSON');
        }

        return [$json, 'application/json'];
    }

    private static function mime(string $contentType): string
    {
        return match (strtolower(trim($contentType))) {
            'form' => 'application/x-www-form-urlencoded',
            'text' => 'text/plain; charset=utf-8',
            default => 'application/json',
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strtolower($key) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{type?: string, user?: string, pass?: string, token?: string}|null $auth
     */
    private static function authorization(?array $auth): ?string
    {
        if ($auth === null || $auth === []) {
            return null;
        }

        $type = strtolower((string) ($auth['type'] ?? (isset($auth['token']) ? 'bearer' : 'basic')));

        if ($type === 'bearer') {
            $token = trim((string) ($auth['token'] ?? ''));

            if ($token === '') {
                throw new \InvalidArgumentException('для Bearer нужен token.');
            }

            return 'Bearer ' . $token;
        }

        $user = (string) ($auth['user'] ?? '');
        $pass = (string) ($auth['pass'] ?? '');

        if ($user === '' && $pass === '') {
            throw new \InvalidArgumentException('для Basic нужны user и pass.');
        }

        return 'Basic ' . base64_encode($user . ':' . $pass);
    }
}

