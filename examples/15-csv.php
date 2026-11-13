<?php

declare(strict_types=1);

/**
 * Пример 15. Вопросы к таблице: модель сама фильтрует CSV.
 *
 * Инструменты: csv_query (только чтение, внутри песочницы), calculator.
 * Флаги: нет.
 *
 * Модель не видит файл целиком — она запрашивает нужные строки и колонки
 * фильтром where, а считает через calculator. Так большие таблицы не
 * раздувают контекст.
 *
 * Запуск:
 *   php examples/15-csv.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\CsvQueryTool;
use Rsgrinko\AiSdk\Tools\FileSandbox;

$root = __DIR__ . '/var/csv';
@mkdir($root, 0777, true);

// Файл пересоздаётся при каждом запуске — пример идемпотентен.
file_put_contents($root . '/orders.csv', implode("\n", [
    'id,city,product,qty,price',
    '1,Тула,чайник,2,1500',
    '2,Москва,утюг,1,3200',
    '3,Тула,утюг,3,3100',
    '4,Казань,чайник,1,1450',
    '5,Тула,пылесос,1,9900',
    '6,Москва,чайник,4,1500',
]) . "\n");

$tools = (new ToolRegistry())->add(
    new CsvQueryTool(new FileSandbox($root)),
    new CalculatorTool(),
);

$agent = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: $tools,
    systemPrompt: 'Данные бери только из csv_query (файл orders.csv), считай через calculator. '
        . 'Отвечай кратко. Когда инструменты вернут результат, напиши по нему итоговый ответ текстом.',
    maxRounds: 6,
);

$answer = $agent->ask('Какие заказы были из Тулы и на какую сумму всего (qty × price)?');

echo $answer . PHP_EOL . PHP_EOL;

foreach ($agent->trace() as $step) {
    echo '  ' . $step['name'] . ' ' . json_encode($step['arguments'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo PHP_EOL . 'Правильный ответ: заказы 1, 3, 5 — 2·1500 + 3·3100 + 1·9900 = 22200.' . PHP_EOL;
