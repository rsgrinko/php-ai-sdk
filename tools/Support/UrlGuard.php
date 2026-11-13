<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools\Support;

/**
 * Защита от SSRF: пропускает только публичные http/https-адреса.
 *
 * Проверяются схема, имя хоста и все адреса хоста — A и AAAA. `localhost`,
 * `.local`, `metadata`, приватные и служебные диапазоны (IPv4 и IPv6, включая
 * CGNAT, NAT64 и 6to4 с приватным адресом внутри) запрещены.
 *
 * Проверенные адреса возвращаются в формате CURLOPT_RESOLVE: cURL подключится
 * ровно к ним и не станет резолвить имя повторно. Без этого злоумышленник с
 * коротким TTL отдал бы при проверке публичный IP, а при запросе — 127.0.0.1
 * (DNS rebinding).
 *
 * Ограничение: при HTTP_PROXY/HTTPS_PROXY имя резолвит прокси, CURLOPT_RESOLVE
 * не действует, а «внутренняя сеть» — это сеть прокси. В такой схеме доступ
 * ограничивают на самом прокси.
 *
 * Флаг allowPrivate снимает проверку адреса (оставляя проверку схемы) — для
 * внутренних API.
 */
final class UrlGuard
{
    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param (\Closure(string): list<string>)|null $resolver имя хоста → его IP;
     *        по умолчанию DNS (A + AAAA). Подменяется в тестах.
     */
    public function __construct(
        private readonly bool $allowPrivate = false,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::resolveDns(...);
    }

    /**
     * @throws \RuntimeException если адрес недопустим
     */
    public function assertAllowed(string $url): void
    {
        $this->pin($url);
    }

    /**
     * Проверить адрес и вернуть строки для CURLOPT_RESOLVE ("host:port:ip,ip").
     * Пустой список — закреплять нечего (IP-литерал или allowPrivate).
     *
     * @return list<string>
     * @throws \RuntimeException если адрес недопустим
     */
    public function pin(string $url): array
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
            return [];
        }

        $host = strtolower((string) $parts['host']);
        $literal = trim($host, '[]');

        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_contains($host, 'metadata')) {
            throw new \RuntimeException('запрос к локальному адресу запрещён');
        }

        // IP-литерал: резолвить нечего, проверяем как есть.
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($literal)) {
                throw new \RuntimeException('запрос к приватному адресу запрещён: ' . $host);
            }

            return [];
        }

        $ips = array_values(array_unique(array_filter(
            ($this->resolver)($host),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
        )));

        if ($ips === []) {
            throw new \RuntimeException('не удалось разрешить хост: ' . $host);
        }

        // Достаточно одного приватного адреса: cURL может выбрать именно его.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('запрос к приватному адресу запрещён: ' . $host);
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $addresses = array_map(
            static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip,
            $ips,
        );

        return [$host . ':' . $port . ':' . implode(',', $addresses)];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // 100.64.0.0/10 (CGNAT) не покрывается флагами PHP.
            return !self::inV4Range($ip, '100.64.0.0', 10);
        }

        $bytes = inet_pton($ip);

        if ($bytes === false || strlen($bytes) !== 16) {
            return false;
        }

        // 2001:db8::/32 — документационный диапазон, в интернете его нет.
        if (str_starts_with($bytes, "\x20\x01\x0d\xb8")) {
            return false;
        }

        // NAT64 (64:ff9b::/96) и 6to4 (2002::/16) несут IPv4 внутри —
        // проверяем его, иначе через шлюз можно достучаться до 127.0.0.1.
        $embedded = match (true) {
            str_starts_with($bytes, "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) => substr($bytes, 12, 4),
            str_starts_with($bytes, "\x20\x02") => substr($bytes, 2, 4),
            default => null,
        };

        if ($embedded !== null) {
            $v4 = inet_ntop($embedded);

            return $v4 !== false && self::isPublicIp($v4);
        }

        return true;
    }

    private static function inV4Range(string $ip, string $network, int $bits): bool
    {
        $long = ip2long($ip);
        $base = ip2long($network);

        if ($long === false || $base === false) {
            return false;
        }

        $mask = -1 << (32 - $bits) & 0xFFFFFFFF;

        return ($long & $mask) === ($base & $mask);
    }

    /**
     * A-записи через gethostbynamel (работает везде), AAAA — через
     * dns_get_record, если доступен.
     *
     * @return list<string>
     */
    private static function resolveDns(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);

            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }
}
