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

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\Cost;
use Rsgrinko\AiSdk\Env;
use Rsgrinko\AiSdk\LoggingClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\TimeTool;

// Приёмник — обычный колбэк. Вместо echo можно писать в свой логгер.
$client = new LoggingClient(
    inner: AiClient::fromEnv(),
    sink: static function (string $line): void {
        echo $line . PHP_EOL;
    },
    verbose: true,
);

$tools = (new ToolRegistry())->add(new TimeTool(), new CalculatorTool());

$agent = new Agent($client, $tools, 'Отвечай кратко, одной строкой. '
    . 'Время, расчёты и другие факты узнавай только вызовом инструмента, из головы их не бери. '
    . 'Когда инструменты вернут результат, напиши по нему итоговый ответ текстом.', 3);

echo PHP_EOL . 'ответ: ' . $agent->ask('Который час в Москве и сколько будет 2+2?') . PHP_EOL;

// Расход виден без декоратора: Agent копит usage по всем раундам.
$usage = $agent->usage();
$model = Env::get('AI_MODEL', 'deepseek-chat') ?? 'deepseek-chat';

// Тарифы примерные — сверяйтесь с провайдером.
$cost = (new Cost())->withRate($model, input: 0.07, output: 0.28);

echo 'токены: ' . $usage['prompt_tokens'] . ' / ' . $usage['completion_tokens'] . PHP_EOL;
echo 'стоимость: ' . $cost->format($cost->estimate($model, $usage)) . PHP_EOL;

// Тот же декоратор без verbose пишет одну строку на запрос — удобно для продакшена:
//
//   $quiet = new LoggingClient(AiClient::fromEnv(), sink: fn ($l) => error_log($l));

