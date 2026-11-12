<?php

declare(strict_types=1);

/**
 * Пример 11. Инструменты без модели.
 *
 * Агент нужен не всегда: инструмент можно вызвать напрямую через реестр.
 * Так удобно делать детерминированные шаги и писать тесты.
 *
 * Инструменты: json_query, convert_units, date_calc, uuid, hash_text.
 * Флаги: нет.
 *
 * Запуск:
 *   php examples/11-json.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\ToolRegistry;
use AiSdk\Tools\ConvertUnitsTool;
use AiSdk\Tools\DateCalcTool;
use AiSdk\Tools\HashTool;
use AiSdk\Tools\JsonQueryTool;
use AiSdk\Tools\UuidTool;

$registry = (new ToolRegistry())->add(
    new JsonQueryTool(),
    new ConvertUnitsTool(),
    new DateCalcTool(),
    new UuidTool(),
    new HashTool(),
);

$json = '{"data":{"items":[{"id":42,"name":"Морковка"}]}}';

// execute() возвращает строку и не бросает исключений — удобно для логирования.
echo $registry->execute('json_query', ['json' => $json, 'path' => 'data.items[0].name']) . PHP_EOL;
echo $registry->execute('json_query', ['json' => $json, 'path' => 'data.items[1].name']) . PHP_EOL;
echo $registry->execute('convert_units', ['value' => 100, 'from' => 'km', 'to' => 'mi']) . PHP_EOL;
echo $registry->execute('date_calc', ['from' => '2026-01-01', 'to' => '2026-12-31']) . PHP_EOL;
echo $registry->execute('uuid', []) . PHP_EOL;
echo $registry->execute('hash_text', ['text' => 'привет']) . PHP_EOL;

// Неизвестный инструмент — текст ошибки, а не исключение.
echo $registry->execute('nope', []) . PHP_EOL;
