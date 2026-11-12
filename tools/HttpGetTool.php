<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Http;
use AiSdk\Tool;

/**
 * GET-запрос к публичному URL.
 *
 * Защита от SSRF: только http/https, только публичные IP, локальные имена
 * и служебные адреса запрещены, лимит размера и таймаут, редиректы проверяются
 * повторно на каждом шаге.
 */
final class HttpGetTool implements Tool
{
    private const MAX_REDIRECTS = 3;
    private const TIMEOUT = 10.0;
    private const MAX_BYTES = 262144;

    public function __construct(private readonly int $defaultChars = 4000)
    {
    }

    public function name(): string
    {
        return 'http_get';
    }

    public function description(): string
    {
        return 'Загрузить текст по URL (API, страница, JSON). '
            . 'Только публичные адреса: локальная сеть и localhost запрещены. '
            . 'Ответ обрезается по max_chars.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Полный адрес, например https://api.example.com/status.',
                ],
                'max_chars' => [
                    'type' => 'integer',
                    'description' => 'Сколько символов вернуть, по умолчанию ' . $this->defaultChars . ', максимум 20000.',
                ],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $url = trim((string) ($args['url'] ?? ''));
        $maxChars = (int) ($args['max_chars'] ?? $this->defaultChars);
        $maxChars = max(200, min($maxChars, 20000));

        if ($url === '') {
            return 'Не указан URL.';
        }

        try {
            $current = $this->normalize($url);
            $response = null;

            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $this->assertPublic($current);
                $response = Http::request($current, self::TIMEOUT, false, self::MAX_BYTES);

                $isRedirect = $response['status'] >= 300 && $response['status'] < 400;

                if (!$isRedirect || $response['location'] === null) {
                    break;
                }

                $current = $this->normalize($this->absolute($current, $response['location']));
            }
        } catch (\Throwable $e) {
            return 'Ошибка: ' . $e->getMessage();
        }

        if ($response === null) {
            return 'Не удалось получить ответ.';
        }

        if ($response['status'] >= 400) {
            return 'HTTP ' . $response['status'] . ' для ' . $current;
        }

        $text = trim($response['body']);

        if ($text === '') {
            return 'Пустой ответ (HTTP ' . $response['status'] . ') для ' . $current . '.';
        }

        $truncated = $response['truncated'] || mb_strlen($text) > $maxChars;
        $text = mb_substr($text, 0, $maxChars);

        return 'HTTP ' . $response['status'] . ', ' . $current
            . ($truncated ? ' (обрезано)' : '')
            . ":\n" . $text;
    }

    private function normalize(string $url): string
    {
        $url = trim($url);

        // Схему не подменяем: пусть assertPublic сам скажет, что ftp не годится.
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

    private function assertPublic(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('некорректный URL');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('разрешены только http и https');
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
}
