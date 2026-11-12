<?php

declare(strict_types=1);

/**
 * Пример 14. Хуки агента: прогресс, инструменты, остановка.
 *
 * Инструменты: get_time, calculator.
 * Флаги: hooks (AgentHooks с onStep, onToolStart, onToolEnd, onAnswer,
 *        shouldStop).
 *
 * Хуки — это колбэки без логики: агент вызывает их в ходе работы, а вы решаете,
 * что делать — печатать прогресс, писать лог, показать кнопку «стоп».
 *
 * Запуск:
 *   php examples/14-hooks.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\AgentHooks;
use AiSdk\AiClient;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\TimeTool;

$tools = (new ToolRegistry())->add(new TimeTool(), new CalculatorTool());

// 1. Полный набор хуков: как это выглядит в консоли.
$hooks = new AgentHooks(
    onStep: static fn (int $step, int $max) => print("шаг {$step}/{$max}" . PHP_EOL),
    onToolStart: static fn (string $name, array $args) => print(
        '  → ' . $name . ' ' . json_encode($args, JSON_UNESCAPED_UNICODE) . PHP_EOL
    ),
    onToolEnd: static fn (array $step) => print(
        '  ← ' . mb_substr(str_replace("\n", ' ', $step['output']), 0, 70) . PHP_EOL
    ),
    onAnswer: static fn (string $text) => print('ответ: ' . $text . PHP_EOL),
);

$agent = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: $tools,
    systemPrompt: 'Отвечай кратко, одной строкой.',
    maxRounds: 4,
    hooks: $hooks,
);

echo '1) обычный ход' . PHP_EOL;
$agent->ask('Который час в Москве и сколько будет 2+2?');

// 2. Остановка: shouldStop проверяется между раундами, поэтому история
//    остаётся целой, а модель больше не вызывается.
echo PHP_EOL . '2) shouldStop: остановка после первого раунда' . PHP_EOL;

$stopped = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: $tools,
    systemPrompt: 'Отвечай кратко.',
    maxRounds: 4,
    hooks: new AgentHooks(
        onStep: static fn (int $step, int $max) => print("шаг {$step}/{$max}" . PHP_EOL),
        shouldStop: static fn (): bool => true,
    ),
);

echo 'ответ: ' . $stopped->ask('Который час в Москве?') . PHP_EOL;
