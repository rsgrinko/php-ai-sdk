<?php

declare(strict_types=1);

/**
 * Пример 4. Интерактивный чат в терминале.
 *
 * История диалога живёт в памяти процесса: агент помнит предыдущие реплики.
 * Команды: /reset — начать заново, /quit — выход.
 *
 * Запуск:
 *   php examples/04-cli.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\AiClient;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\TimeTool;
use AiSdk\Tools\WeatherTool;

$tools = (new ToolRegistry())->add(
    new TimeTool(),
    new CalculatorTool(),
    new WeatherTool(),
);

$agent = new Agent(AiClient::fromEnv(), $tools);

echo "Чат с моделью. /reset — очистить историю, /quit — выход.\n";

while (true) {
    echo "\n> ";
    $line = fgets(STDIN);

    if ($line === false) {
        break;
    }

    $line = trim($line);

    if ($line === '') {
        continue;
    }

    if (in_array($line, ['/quit', '/exit', 'exit', 'quit'], true)) {
        break;
    }

    if ($line === '/reset') {
        $agent->reset();
        echo "история очищена\n";

        continue;
    }

    try {
        echo $agent->ask($line) . PHP_EOL;

        foreach ($agent->trace() as $step) {
            echo '  ↳ ' . $step['name'] . ' → ' . mb_substr($step['output'], 0, 120) . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo 'Ошибка: ' . $e->getMessage() . PHP_EOL;
    }
}
