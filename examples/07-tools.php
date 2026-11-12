<?php

declare(strict_types=1);

/**
 * Пример 7. Новые безопасные инструменты: единицы, даты, uuid, хеш.
 *
 * Эти инструменты не требуют ни ключей, ни настроек — их можно
 * зарегистрировать и сразу спрашивать.
 *
 * Инструменты: convert_units, date_calc, uuid, hash_text.
 * Флаги: нет.
 *
 * Запуск:
 *   php examples/07-tools.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\AiClient;
use AiSdk\ToolRegistry;
use AiSdk\Tools\ConvertUnitsTool;
use AiSdk\Tools\DateCalcTool;
use AiSdk\Tools\HashTool;
use AiSdk\Tools\UuidTool;

$tools = (new ToolRegistry())->add(
    new ConvertUnitsTool(),
    new DateCalcTool(),
    new UuidTool(),
    new HashTool(),
);

$agent = new Agent(AiClient::fromEnv(), $tools, 'Отвечай кратко, одной строкой на вопрос.', 4);

$answer = $agent->ask(
    'Переведи 100 км в мили, посчитай сколько дней между 2026-01-01 и 2026-03-15 '
    . 'и сгенерируй один uuid.'
);

echo $answer . PHP_EOL;

foreach ($agent->trace() as $step) {
    echo '  ' . $step['name'] . '(' . json_encode($step['arguments'], JSON_UNESCAPED_UNICODE) . ')'
        . ' → ' . mb_substr($step['output'], 0, 100) . PHP_EOL;
}

