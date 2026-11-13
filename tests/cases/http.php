<?php

declare(strict_types=1);

// Тесты: http. Запускаются из tests/run.php.

use Rsgrinko\AiSdk\AiException;
use Rsgrinko\AiSdk\HttpTransport;
use Rsgrinko\AiSdk\Tools\Support\HttpRequest;
use Rsgrinko\AiSdk\Tool;
use Rsgrinko\AiSdk\Tools\HttpRequestTool;
use Rsgrinko\AiSdk\Tools\Support\UrlGuard;

// ---------------------------------------------------------------------------
// http_request: сборка запроса (без сети)
// ---------------------------------------------------------------------------

test('http_request: query добавляется к URL', function (): void {
    $request = HttpRequest::build('get', 'https://example.com/search', ['q' => 'кот', 'page' => 2]);

    assertSame('GET', $request->method);
    assertContains('q=%D0%BA%D0%BE%D1%82', $request->url);
    assertContains('page=2', $request->url);
});

test('http_request: JSON-тело и Content-Type', function (): void {
    $request = HttpRequest::build('POST', 'https://example.com/orders', body: ['sku' => 'A-1']);

    assertSame('{"sku":"A-1"}', $request->body);
    assertSame('application/json', $request->headers['Content-Type']);
    assertSame('POST', $request->method);
});

test('http_request: form-тело', function (): void {
    $request = HttpRequest::build(
        'POST',
        'https://example.com/login',
        body: ['u' => 'a', 'p' => 'b'],
        contentType: 'form',
    );

    assertSame('u=a&p=b', $request->body);
    assertSame('application/x-www-form-urlencoded', $request->headers['Content-Type']);
});

test('http_request: Basic-авторизация', function (): void {
    $request = HttpRequest::build(
        'GET',
        'https://example.com/me',
        auth: ['user' => 'bot', 'pass' => 'secret'],
    );

    assertSame('Basic ' . base64_encode('bot:secret'), $request->headers['Authorization']);
});

test('http_request: Bearer-авторизация', function (): void {
    $request = HttpRequest::build(
        'GET',
        'https://example.com/me',
        auth: ['type' => 'bearer', 'token' => 'abc'],
    );

    assertSame('Bearer abc', $request->headers['Authorization']);
});

test('http_request: запрещённые заголовки отбрасываются', function (): void {
    $request = HttpRequest::build(
        'GET',
        'https://example.com',
        headers: ['Host' => 'evil', 'X-Ok' => '1'],
    );

    assertTrue(!isset($request->headers['Host']), 'Host должен отбрасываться');
    assertSame('1', $request->headers['X-Ok']);
});

test('http_request: неизвестный метод отвергается', function (): void {
    assertThrows(static fn () => HttpRequest::build('TRACE', 'https://example.com'));
});

test('http_request: без схемы подставляется https', function (): void {
    assertSame('https://example.com/x', HttpRequest::build('GET', 'example.com/x')->url);
});

test('http: заголовки превращаются в строки cURL', function (): void {
    // Регрессия: ассоциативный массив, отданный в cURL напрямую,
    // молча терял и Content-Type, и Authorization.
    assertSame(['X-Ok: 1'], HttpTransport::headerLines(['X-Ok' => '1']));
    assertSame(['Accept: */*'], HttpTransport::headerLines([]));
    assertSame(['Accept: */*'], HttpTransport::headerLines(['Accept: */*']));
});

// ---------------------------------------------------------------------------
// http_request: защита от SSRF (без сети)
// ---------------------------------------------------------------------------

test('http_request: localhost запрещён', function (): void {
    assertContains('локальному адресу', (new HttpRequestTool())->handle(['url' => 'http://localhost/x']));
});

test('http_request: приватные адреса запрещены', function (): void {
    $tool = new HttpRequestTool();

    assertContains('приватному адресу', $tool->handle(['url' => 'http://127.0.0.1/x']));
    assertContains('приватному адресу', $tool->handle(['url' => 'http://10.0.0.5/x']));
    assertContains('приватному адресу', $tool->handle(['url' => 'http://192.168.1.10/x']));
});

test('http_request: только http и https', function (): void {
    assertContains('только http и https', (new HttpRequestTool())->handle(['url' => 'ftp://example.com/x']));
});

test('http_request: allowPrivate снимает блокировку внутренней сети', function (): void {
    // Порт закрыт: проверяем именно то, что SSRF-отказ больше не выдаётся.
    $result = (new HttpRequestTool(allowPrivate: true))->handle(['url' => 'http://127.0.0.1:9/x']);

    assertTrue(
        !str_contains($result, 'приватному адресу'),
        'с allowPrivate адрес не должен блокироваться: ' . $result
    );
});

// ---------------------------------------------------------------------------
// UrlGuard: IPv6, закрепление адресов (DNS rebinding)
// ---------------------------------------------------------------------------

/**
 * UrlGuard с подменённым DNS: хост → заданные адреса.
 *
 * @param array<string, list<string>> $dns
 */
function guardWithDns(array $dns): UrlGuard
{
    return new UrlGuard(resolver: static fn (string $host): array => $dns[$host] ?? []);
}

test('url_guard: публичный хост закрепляется на проверенных адресах', function (): void {
    $guard = guardWithDns(['api.example.com' => ['93.184.216.34', '2606:2800:220:1::1']]);

    assertSame(
        ['api.example.com:443:93.184.216.34,[2606:2800:220:1::1]'],
        $guard->pin('https://api.example.com/v1')
    );
    assertSame(
        ['api.example.com:8080:93.184.216.34,[2606:2800:220:1::1]'],
        $guard->pin('http://api.example.com:8080/')
    );
});

test('url_guard: один приватный адрес среди публичных — отказ', function (): void {
    $guard = guardWithDns([
        'mixed.example' => ['93.184.216.34', '127.0.0.1'],
        'v6.example' => ['93.184.216.34', '::1'],
    ]);

    assertThrows(static fn () => $guard->pin('https://mixed.example/'));
    assertThrows(static fn () => $guard->pin('https://v6.example/'));
});

test('url_guard: хост без адресов — отказ', function (): void {
    assertThrows(static fn () => guardWithDns([])->pin('https://nowhere.example/'));
});

test('url_guard: приватные и служебные IPv6 запрещены', function (): void {
    foreach ([
        '::1',               // loopback
        '::ffff:127.0.0.1',  // IPv4-mapped
        'fc00::1',           // ULA
        'fe80::1',           // link-local
        '64:ff9b::7f00:1',   // NAT64 → 127.0.0.1
        '64:ff9b::a00:5',    // NAT64 → 10.0.0.5
        '2002:7f00:1::1',    // 6to4 → 127.0.0.1
        '2001:db8::1',       // документационный
    ] as $ip) {
        assertTrue(!UrlGuard::isPublicIp($ip), 'должен быть запрещён: ' . $ip);
    }

    assertTrue(UrlGuard::isPublicIp('2606:4700::1111'), 'публичный IPv6');
    assertTrue(UrlGuard::isPublicIp('64:ff9b::808:808'), 'NAT64 → 8.8.8.8 — публичный');
    assertTrue(!UrlGuard::isPublicIp('100.64.1.1'), 'CGNAT');
});

test('url_guard: IPv6-литерал в URL проверяется', function (): void {
    $tool = new HttpRequestTool();

    assertContains('приватному адресу', $tool->handle(['url' => 'http://[::1]/x']));
    assertContains('приватному адресу', $tool->handle(['url' => 'http://[::ffff:127.0.0.1]/x']));
});

test('url_guard: *.localhost запрещён', function (): void {
    assertContains('локальному адресу', (new HttpRequestTool())->handle(['url' => 'http://app.localhost/']));
});

test('http: cURL подключается к закреплённому адресу, а не резолвит имя', function (): void {
    // Имя в зоне .invalid не резолвится никогда. Если cURL пошёл
    // по закреплённому 127.0.0.1, ошибка будет про соединение, а не про DNS.
    // Прокси из окружения резолвит имена сам, поэтому для теста его обходим.
    $noProxy = getenv('NO_PROXY');
    putenv('NO_PROXY=pinned.invalid');

    try {
        $control = '';
        $pinned = '';

        try {
            HttpTransport::request('http://pinned.invalid:9/', 2.0);
        } catch (AiException $e) {
            $control = $e->getMessage();
        }

        try {
            HttpTransport::request('http://pinned.invalid:9/', 2.0, resolve: ['pinned.invalid:9:127.0.0.1']);
        } catch (AiException $e) {
            $pinned = $e->getMessage();
        }
    } finally {
        putenv($noProxy === false ? 'NO_PROXY' : 'NO_PROXY=' . $noProxy);
    }

    assertContains('resolve', $control, 'без закрепления имя должно не резолвиться');
    assertTrue($pinned !== '' && stripos($pinned, 'resolve') === false, 'имя не должно резолвиться: ' . $pinned);
});


