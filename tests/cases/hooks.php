<?php

declare(strict_types=1);

// Тесты: хуки агента — шаги, инструменты, ответ, остановка.

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AgentHooks;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\FinishTool;

test('hooks: шаги, инструменты и ответ идут по порядку', function (): void {
    $events = [];

    $hooks = new AgentHooks(
        onStep: static function (int $step, int $max) use (&$events): void {
            $events[] = "step {$step}/{$max}";
        },
        onToolStart: static function (string $name, array $args) use (&$events): void {
            $events[] = 'start ' . $name;
        },
        onToolEnd: static function (array $step) use (&$events): void {
            $events[] = 'end ' . $step['name'] . ' -> ' . $step['output'];
        },
    );

    $client = new FakeClient([
        toolCall('calculator', ['expression' => '2+2'], 'c1'),
        textResult('4'),
    ]);

    $agent = new Agent(
        client: $client,
        tools: (new ToolRegistry())->add(new CalculatorTool()),
        systemPrompt: 'тест',
        maxRounds: 3,
        hooks: $hooks,
    );

    assertSame('4', $agent->ask('посчитай'));

    assertSame([
        'step 1/3',
        'start calculator',
        'end calculator -> 2+2 = 4',
        'step 2/3',
    ], $events);
});

test('hooks: shouldStop останавливает между раундами', function (): void {
    $client = new FakeClient([
        toolCall('calculator', ['expression' => '2+2'], 'c1'),
    ]);

    $agent = new Agent(
        client: $client,
        tools: (new ToolRegistry())->add(new CalculatorTool()),
        systemPrompt: 'тест',
        maxRounds: 3,
        hooks: new AgentHooks(shouldStop: static fn (): bool => true),
    );

    assertSame('Остановлено пользователем.', $agent->ask('посчитай'));
    assertSame(1, count($client->requests), 'после остановки модель не вызывается');

    $messages = $agent->messages();
    assertSame('assistant', $messages[count($messages) - 1]['role']);
});

test('hooks: исключение в колбэке не ломает ответ', function (): void {
    $client = new FakeClient([textResult('ок')]);

    $agent = new Agent(
        client: $client,
        tools: new ToolRegistry(),
        systemPrompt: 'тест',
        maxRounds: 2,
        hooks: new AgentHooks(
            onStep: static function (): void {
                throw new RuntimeException('лог упал');
            },
        ),
    );

    assertSame('ок', $agent->ask('вопрос'));
});

test('hooks: finish виден как инструмент и как ответ', function (): void {
    $events = [];

    $hooks = new AgentHooks(
        onToolStart: static function (string $name) use (&$events): void {
            $events[] = 'start ' . $name;
        },
    );

    $client = new FakeClient([
        toolCalls([['name' => 'finish', 'arguments' => ['result' => 'готово'], 'id' => 'f1']]),
    ]);

    $agent = new Agent(
        client: $client,
        tools: (new ToolRegistry())->add(new FinishTool()),
        systemPrompt: 'тест',
        maxRounds: 2,
        hooks: $hooks,
    );

    assertSame('готово', $agent->ask('сделай'));
    assertSame(['start finish'], $events);
});

