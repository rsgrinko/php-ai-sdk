<?php

declare(strict_types=1);

// Тесты: таймаут от модели, стоимость запроса, накопление usage.

use AiSdk\Agent;
use AiSdk\ChatResult;
use AiSdk\Cost;
use AiSdk\ToolRegistry;
use AiSdk\Tools\HttpRequestTool;
use AiSdk\Tools\ShellTool;
use AiSdk\Tools\Support\Timeout;

test('timeout: значение модели в пределах максимума', function (): void {
    $timeout = Timeout::from(['timeout' => 5], 10.0, 60.0);

    assertSame(5.0, $timeout->seconds);
    assertSame(false, $timeout->clamped());
});

test('timeout: без параметра берётся значение по умолчанию', function (): void {
    assertSame(10.0, Timeout::from([], 10.0, 60.0)->seconds);
});

test('timeout: больше максимума урезается', function (): void {
    $timeout = Timeout::from(['timeout' => 999], 10.0, 60.0);

    assertSame(60.0, $timeout->seconds);
    assertSame(true, $timeout->clamped());
});

test('timeout: меньше секунды поднимается до одной', function (): void {
    assertSame(1.0, Timeout::from(['timeout' => 0.1], 10.0, 60.0)->seconds);
});

test('timeout: нечисловое значение игнорируется', function (): void {
    assertSame(10.0, Timeout::from(['timeout' => 'abc'], 10.0, 60.0)->seconds);
});

test('tools: timeout есть в схемах run_command и http_request', function (): void {
    assertTrue(isset((new ShellTool())->parameters()['properties']['timeout']));
    assertTrue(isset((new HttpRequestTool())->parameters()['properties']['timeout']));
});

test('cost: считает по тарифу за миллион токенов', function (): void {
    $cost = (new Cost())->withRate('demo', 1.0, 2.0);
    $estimate = $cost->estimate('demo', ['prompt_tokens' => 1000, 'completion_tokens' => 500]);

    assertSame(0.002, round((float) $estimate, 9));
});

test('cost: неизвестная модель — null и прочерк', function (): void {
    $cost = new Cost();

    assertSame(null, $cost->estimate('nope', ['prompt_tokens' => 10]));
    assertSame('—', $cost->format(null));
});

test('cost: форматирование суммы', function (): void {
    $cost = new Cost();

    assertSame('$0', $cost->format(0.0));
    assertSame('$0.000412', $cost->format(0.000412));
});

test('agent: usage суммируется по запросам и сбрасывается', function (): void {
    $client = new FakeClient([
        new ChatResult('a', [], ['role' => 'assistant', 'content' => 'a'], ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        new ChatResult('b', [], ['role' => 'assistant', 'content' => 'b'], ['prompt_tokens' => 20, 'completion_tokens' => 7]),
    ]);

    $agent = new Agent($client, new ToolRegistry(), 'тест', 3);

    $agent->ask('раз');
    $agent->ask('два');

    assertSame(['prompt_tokens' => 30, 'completion_tokens' => 12], $agent->usage());

    $agent->reset();
    assertSame(['prompt_tokens' => 0, 'completion_tokens' => 0], $agent->usage());
});
