<?php

declare(strict_types=1);

/**
 * Пример 12. Логирование запросов к модели.
 *
 * Инструменты: get_time, calculator.
 * Флаги: verbose (печатать сообщения и инструменты), sink (свой приёмник строк).
 *
 * Декоратор оборачивает любой ChatClient: AiClient, FakeClient, свой.
 *
 * Запуск:
 *   php examples/12-logging.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\AiClient;
use AiSdk\LoggingClient;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\TimeTool;

// Приёмник — обычный колбэк. Вместо echo можно писать в свой логгер.
$client = new LoggingClient(
    inner: AiClient::fromEnv(),
    sink: static function (string $line): void {
        echo $line . PHP_EOL;
    },
    verbose: true,
);

$tools = (new ToolRegistry())->add(new TimeTool(), new CalculatorTool());

$agent = new Agent($client, $tools, 'Отвечай кратко, одной строкой.', 3);

echo PHP_EOL . 'ответ: ' . $agent->ask('Который час в Москве и сколько будет 2+2?') . PHP_EOL;

// Тот же декоратор без verbose пишет одну строку на запрос — удобно для продакшена:
//
//   $quiet = new LoggingClient(AiClient::fromEnv(), sink: fn ($l) => error_log($l));
