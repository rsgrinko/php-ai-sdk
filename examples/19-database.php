<?php

declare(strict_types=1);

/**
 * Пример 19. Агент с доступом к MySQL/MariaDB.
 *
 * Инструменты: sql_query (DatabaseTool).
 * Флаги: readOnly (только чтение / полный доступ), about, toolApproval.
 *
 * Подключение берётся из .env:
 *   DB_HOST=127.0.0.1
 *   DB_PORT=3306
 *   DB_NAME=shop
 *   DB_USER=reader
 *   DB_PASSWORD=secret
 *
 * Часть 2 пишет в базу: создаёт, наполняет и удаляет таблицу aisdk_demo.
 * Запускайте на тестовой базе.
 *
 * Запуск:
 *   php examples/19-database.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\Env;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\DatabaseTool;

$host = Env::get('DB_HOST', '') ?? '';

if ($host === '') {
    exit('Задайте DB_HOST, DB_NAME, DB_USER, DB_PASSWORD в .env' . PHP_EOL);
}

$connect = static fn (bool $readOnly): DatabaseTool => DatabaseTool::mysql(
    host: $host,
    database: Env::get('DB_NAME', '') ?? '',
    user: Env::get('DB_USER', '') ?? '',
    password: Env::get('DB_PASSWORD', '') ?? '',
    port: Env::int('DB_PORT', 3306),
    readOnly: $readOnly,
);

$printTrace = static function (Agent $agent): void {
    foreach ($agent->trace() as $step) {
        echo '   ' . ($step['blocked'] ? '[запрещено] ' : '') . ($step['arguments']['query'] ?? '') . PHP_EOL
            . '     → ' . mb_substr(str_replace("\n", ' / ', $step['output']), 0, 110) . PHP_EOL;
    }
};

// 1. Только чтение: модель изучает базу сама.
echo '1) только чтение' . PHP_EOL;

$reader = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: (new ToolRegistry())->add($connect(readOnly: true)),
    systemPrompt: 'Ты аналитик базы данных. Данные получай только через sql_query, не выдумывай. '
        . 'Когда инструменты вернут результат, напиши по нему итоговый ответ текстом. Отвечай кратко.',
    maxRounds: 6,
);

echo $reader->ask(
    'Сколько в базе таблиц? Назови 5 самых больших по числу строк '
    . '(используй information_schema.tables для текущей базы).'
) . PHP_EOL;
$printTrace($reader);

echo PHP_EOL . '   попытка удалить:' . PHP_EOL;
echo '   ' . $reader->ask('Удали таблицу news.') . PHP_EOL;

// 2. Полный доступ, но toolApproval пускает только запросы к aisdk_demo:
//    так модель может писать, не трогая остальные таблицы.
echo PHP_EOL . '2) полный доступ, ограниченный toolApproval' . PHP_EOL;

$writer = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: (new ToolRegistry())->add($connect(readOnly: false)),
    systemPrompt: 'Ты работаешь с базой через sql_query, по одному запросу за вызов. '
        . 'Когда инструменты вернут результат, напиши по нему итоговый ответ текстом. Отвечай кратко.',
    maxRounds: 8,
    parallelToolCalls: false,
    toolApproval: static fn (string $name, array $args): bool
        => str_contains(strtolower((string) ($args['query'] ?? '')), 'aisdk_demo'),
);

echo $writer->ask(
    'Создай таблицу aisdk_demo (id INT AUTO_INCREMENT PRIMARY KEY, city VARCHAR(50), temp INT), '
    . 'добавь в неё Тулу +3, Москву +1 и Казань -2, затем выведи города со средней температурой '
    . 'и в конце удали таблицу aisdk_demo.'
) . PHP_EOL;
$printTrace($writer);
