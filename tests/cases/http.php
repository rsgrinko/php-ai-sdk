<?php

declare(strict_types=1);

// Тесты: http. Запускаются из tests/run.php.

use AiSdk\Agent;
use AiSdk\ChatClient;
use AiSdk\ChatResult;
use AiSdk\HttpTransport;
use AiSdk\HttpRequest;
use AiSdk\LoggingClient;
use AiSdk\Tool;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\ConvertUnitsTool;
use AiSdk\Tools\CsvQueryTool;
use AiSdk\Tools\DateCalcTool;
use AiSdk\Tools\FileSandbox;
use AiSdk\Tools\FindFilesTool;
use AiSdk\Tools\JsonQueryTool;
use AiSdk\Tools\FinishTool;
use AiSdk\Tools\HashTool;
use AiSdk\Tools\HttpRequestTool;
use AiSdk\Tools\ListDirTool;
use AiSdk\Tools\ReadFileTool;
use AiSdk\Tools\ReplaceInFileTool;
use AiSdk\Tools\SearchFilesTool;
use AiSdk\Tools\ShellTool;
use AiSdk\Tools\TimeTool;
use AiSdk\Tools\WriteFileTool;
use AiSdk\Tools\UuidTool;

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


