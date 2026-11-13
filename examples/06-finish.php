<?php

declare(strict_types=1);

/**
 * Пример 6. Инструмент finish — явное завершение работы.
 *
 * Два сценария:
 *   1. Структурированный ответ: кроме finish инструментов нет, а tool_choice
 *      заставляет модель ответить именно через него. Ответ гарантированно
 *      лежит в аргументе result, а не в свободном тексте.
 *   2. Работа + завершение: сначала калькулятор, потом finish.
 *
 * Инструменты: finish, calculator.
 * Флаги: toolChoice=required, finishTool=finish.
 *
 * Запуск:
 *   php examples/06-finish.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\FinishTool;

$client = AiClient::fromEnv();

// --- 1. Структурированный ответ --------------------------------------------
// Инструмент один, поэтому result неизбежно содержит ответ в нужном формате.

$classifier = new Agent(
    client: $client,
    tools: (new ToolRegistry())->add(new FinishTool()),
    systemPrompt: 'Ты классифицируешь обращения в поддержку. '
        . 'Ответ — одна строка из трёх частей через точку с запятой: категория, срочность, суть. '
        . 'Категория: доступ, оплата, баг или вопрос. Срочность: низкая или высокая. '
        . 'Пример: баг; высокая; не открывается страница входа. '
        . 'Без пояснений, скобок и кавычек.',
    maxRounds: 2,
    toolChoice: 'required',
);

$text = $classifier->ask('«Не приходит SMS с кодом подтверждения, уже второй день, не могу войти»');

echo "1) классификация через finish\n";
echo '   result: ' . $text . PHP_EOL;
echo '   вызовов: ' . count($classifier->trace()) . PHP_EOL . PHP_EOL;

// --- 2. Сначала работа, потом завершение -----------------------------------

$worker = new Agent(
    client: $client,
    tools: (new ToolRegistry())->add(new CalculatorTool(), new FinishTool()),
    systemPrompt: 'Считай калькулятором, не в уме. '
        . 'Когда ответ готов — обязательно вызови finish и положи ответ в result.',
    maxRounds: 4,
);

$answer = $worker->ask('Сколько будет 17% от 4300? Ответь числом.');

echo "2) расчёт и завершение\n";
echo '   ответ: ' . $answer . PHP_EOL;

foreach ($worker->trace() as $step) {
    echo '   ' . $step['name'] . '(' . json_encode($step['arguments'], JSON_UNESCAPED_UNICODE) . ')'
        . ' → ' . $step['output'] . PHP_EOL;
}

