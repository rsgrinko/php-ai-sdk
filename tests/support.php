<?php

declare(strict_types=1);

// Общие помощники и заглушки для тестов.

use Rsgrinko\AiSdk\ChatClient;
use Rsgrinko\AiSdk\ChatResult;

/**
 * Тесты SDK: без сети, без модели и без API-ключа.
 *
 * Модель подменяется FakeClient — он отдаёт заранее заготовленные ответы.
 * Именно так удобно тестировать агентов в своих проектах.
 *
 * Запуск:
 *   php tests/run.php
 */

require dirname(__DIR__) . '/bootstrap.php';

mb_internal_encoding('UTF-8');

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        $passed++;
        echo 'ok   ' . $name . PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $name . PHP_EOL . '     ' . $e->getMessage() . PHP_EOL;
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'ожидалось ' . var_export($expected, true)
            . ', получено ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : 'ожидалось true');
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'нет "' . $needle . '" в "' . $haystack . '"'
        );
    }
}

function assertThrows(callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message !== '' ? $message : 'ожидалось исключение');
}

/**
 * Модель-заглушка: отдаёт ответы по очереди и запоминает все запросы.
 */
final class FakeClient implements ChatClient
{
    /** @var list<array{messages: array<int, mixed>, tools: array<int, mixed>, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param list<ChatResult> $results
     */
    public function __construct(private array $results)
    {
    }

    public function chat(array $messages, array $tools = [], array $options = []): ChatResult
    {
        $this->requests[] = ['messages' => $messages, 'tools' => $tools, 'options' => $options];

        $result = array_shift($this->results);

        if ($result === null) {
            throw new RuntimeException('FakeClient: закончились заготовленные ответы');
        }

        return $result;
    }
}

/**
 * Ответ «вызови инструмент».
 *
 * @param array<string, mixed> $arguments
 */
function toolCall(string $name, array $arguments, string $id = 'call_1'): ChatResult
{
    $message = [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
            ],
        ]],
    ];

    return new ChatResult('', [['id' => $id, 'name' => $name, 'arguments' => $arguments]], $message);
}

function textResult(string $text): ChatResult
{
    return new ChatResult($text, [], ['role' => 'assistant', 'content' => $text]);
}

/**
 * Временный каталог для файловых инструментов.
 */
function testDir(): string
{
    $base = __DIR__ . '/tmp';

    if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
        throw new RuntimeException('не удалось создать tests/tmp');
    }

    $dir = $base . '/sandbox-' . bin2hex(random_bytes(3));

    if (!mkdir($dir, 0777, true)) {
        throw new RuntimeException('не удалось создать ' . $dir);
    }

    return $dir;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir) ?: [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path)) {
            removeDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * Ответ модели с несколькими вызовами сразу.
 *
 * @param list<array{name: string, arguments: array<string, mixed>, id: string}> $calls
 */
function toolCalls(array $calls): ChatResult
{
    $message = ['role' => 'assistant', 'content' => '', 'tool_calls' => []];
    $parsed = [];

    foreach ($calls as $call) {
        $message['tool_calls'][] = [
            'id' => $call['id'],
            'type' => 'function',
            'function' => [
                'name' => $call['name'],
                'arguments' => json_encode($call['arguments'], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $parsed[] = [
            'id' => $call['id'],
            'name' => $call['name'],
            'arguments' => $call['arguments'],
        ];
    }

    return new ChatResult('', $parsed, $message);
}

