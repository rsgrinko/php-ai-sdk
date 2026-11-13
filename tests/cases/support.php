<?php

declare(strict_types=1);

// Тесты: таймаут от модели, стоимость запроса, накопление usage.

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\ChatResult;
use Rsgrinko\AiSdk\Cost;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\HttpRequestTool;
use Rsgrinko\AiSdk\Tools\ShellTool;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\Support\Number;
use Rsgrinko\AiSdk\Tools\Support\Timeout;

test('timeout: значение модели в пределах максимума', function (): void {
    assertSame(5.0, Timeout::seconds(['timeout' => 5], 10.0, 60.0));
});

test('timeout: без параметра берётся значение по умолчанию', function (): void {
    assertSame(10.0, Timeout::seconds([], 10.0, 60.0));
});

test('timeout: больше максимума урезается', function (): void {
    assertSame(60.0, Timeout::seconds(['timeout' => 999], 10.0, 60.0));
});

test('timeout: меньше секунды поднимается до одной', function (): void {
    assertSame(1.0, Timeout::seconds(['timeout' => 0.1], 10.0, 60.0));
});

test('timeout: нечисловое значение игнорируется', function (): void {
    assertSame(10.0, Timeout::seconds(['timeout' => 'abc'], 10.0, 60.0));
});

test('number: большие числа не превращаются в мусор', function (): void {
    assertSame('4', Number::format(4.0));
    assertSame('0.3333333333', Number::format(1 / 3));
    assertSame('-2.5', Number::format(-2.5));
    assertSame('0', Number::format(-0.0));
    assertContains('E+28', Number::format(9.0 ** 30));
    assertContains('E+28', (new CalculatorTool())->handle(['expression' => '9^30']));
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
