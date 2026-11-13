<?php

declare(strict_types=1);

/**
 * Пример 2. Агент с инструментами.
 *
 * Модель сама решает, какой инструмент вызвать: время, калькулятор или погоду.
 * Мы только регистрируем инструменты и задаём вопрос.
 *
 * Инструменты: get_time, calculator, get_weather.
 * Флаги: нет (значения по умолчанию).
 *
 * Запуск:
 *   php examples/02-tools.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\TimeTool;
use Rsgrinko\AiSdk\Tools\WeatherTool;

$tools = new ToolRegistry();
$tools->add(
    new TimeTool(),
    new CalculatorTool(),
    new WeatherTool(),
);

$agent = new Agent(AiClient::fromEnv(), $tools);

$answer = $agent->ask('Который час в Москве? И сколько будет 17% от 4300?');

echo $answer . PHP_EOL;

// Что реально вызывалось — для отладки и логов.
$trace = $agent->trace();

if ($trace !== []) {
    echo PHP_EOL . 'Вызовы инструментов:' . PHP_EOL;

    foreach ($trace as $step) {
        $arguments = json_encode($step['arguments'], JSON_UNESCAPED_UNICODE);
        echo '  ' . $step['name'] . '(' . $arguments . ')'
            . ' → ' . $step['output']
            . ' [' . $step['ms'] . ' мс]' . PHP_EOL;
    }
}

