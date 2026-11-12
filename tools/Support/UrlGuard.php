<?php

declare(strict_types=1);

namespace AiSdk\Tools\Support;

/**
 * Защита от SSRF: пропускает только публичные http/https-адреса.
 *
 * Проверяются схема, имя хоста и все A-записи. `localhost`, `.local`,
 * `metadata`, приватные диапазоны и CGNAT запрещены. Флаг allowPrivate
 * снимает проверку адреса (оставляя проверку схемы) — для внутренних API.
 */
final class UrlGuard
{
    public function __construct(private readonly bool $allowPrivate = false)
    {
    }

    /**
     * @throws \RuntimeException если адрес недопустим
     */
    public function assertAllowed(string $url): void
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
}
