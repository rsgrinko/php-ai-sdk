<?php

declare(strict_types=1);

// Тесты: logging. Запускаются из tests/run.php.

use AiSdk\Agent;
use AiSdk\ChatClient;
use AiSdk\ChatResult;
use AiSdk\LoggingClient;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\TimeTool;

// ---------------------------------------------------------------------------
// Логирование
// ---------------------------------------------------------------------------

test('logging: прокидывает ответ и пишет строки', function (): void {
    $lines = [];
    $client = new LoggingClient(
        new FakeClient([textResult('привет')]),
        sink: static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    $result = $client->chat([['role' => 'user', 'content' => 'вопрос']]);

    assertSame('привет', $result->text);
    assertSame(2, count($lines), 'ожидались две строки: запрос и ответ');
    assertContains('LLM →', $lines[0]);
    assertContains('LLM ←', $lines[1]);
    assertContains('текст 6 симв.', $lines[1]);
});

test('logging: ошибка пишется и пробрасывается', function (): void {
    $broken = new class implements ChatClient {
        public function chat(array $messages, array $tools = [], array $options = []): ChatResult
        {
            throw new RuntimeException('сеть упала');
        }
    };

    $lines = [];
    $client = new LoggingClient($broken, sink: static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });

    assertThrows(static fn () => $client->chat([['role' => 'user', 'content' => 'x']]));

    $log = implode("\n", $lines);

    assertContains('LLM ✗', $log);
    assertContains('сеть упала', $log);
});

test('logging: verbose печатает сообщения и инструменты', function (): void {
    $lines = [];
    $client = new LoggingClient(
        new FakeClient([textResult('ок')]),
        sink: static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
        verbose: true,
    );

    $tools = (new ToolRegistry())->add(new TimeTool())->definitions();
    $client->chat([['role' => 'user', 'content' => 'который час']], $tools);

    $log = implode("\n", $lines);

    assertContains('[0] user: который час', $log);
    assertContains('инструменты: get_time', $log);
    assertContains('ответ: ок', $log);
});

test('logging: в агенте логирует каждый раунд', function (): void {
    $lines = [];
    $client = new LoggingClient(
        new FakeClient([
            toolCall('calculator', ['expression' => '2+2'], 'c1'),
            textResult('4'),
        ]),
        sink: static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    $agent = new Agent($client, (new ToolRegistry())->add(new CalculatorTool()), 'тест', 3);

    assertSame('4', $agent->ask('посчитай'));

    $log = implode("\n", $lines);

    assertContains('tool_calls: calculator', $log);
    assertSame(4, count($lines), 'два раунда — по две строки');
});


