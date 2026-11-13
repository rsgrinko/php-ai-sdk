<?php

declare(strict_types=1);

/**
 * Пример 22. Отчёт по базе: агент изучает схему и сдаёт итог через finish.
 *
 * Инструменты: sql_query (только чтение), finish.
 * Флаги: about, finishTool, хуки для прогресса.
 *
 * Агент сам решает, какие запросы сделать, а результат кладёт в finish —
 * его можно сохранить в файл или отправить в мессенджер. Отчёт пишется
 * в examples/var/db-report.md.
 *
 * База: MySQL из DB_* в .env.
 *
 * Запуск:
 *   php examples/22-database-report.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AgentHooks;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\Env;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\DatabaseTool;
use Rsgrinko\AiSdk\Tools\FinishTool;

$host = Env::get('DB_HOST', '') ?? '';

if ($host === '') {
    exit('Задайте DB_HOST, DB_NAME, DB_USER, DB_PASSWORD в .env' . PHP_EOL);
}

$database = Env::get('DB_NAME', '') ?? '';

$db = DatabaseTool::mysql(
    host: $host,
    database: $database,
    user: Env::get('DB_USER', '') ?? '',
    password: Env::get('DB_PASSWORD', '') ?? '',
    port: Env::int('DB_PORT', 3306),
    // Конкретная подсказка: слабые модели путаются в DATABASE() и кавычках.
    about: 'База: ' . $database . '. Список таблиц с числом строк: '
        . "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = '"
        . $database . "' ORDER BY table_rows DESC.",
);

$agent = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: (new ToolRegistry())->add($db, new FinishTool()),
    systemPrompt: 'Ты аналитик баз данных. Изучи базу через sql_query: число таблиц посчитай '
        . 'запросом с COUNT(*) (список обрезается, по нему считать нельзя), затем список таблиц '
        . 'с числом строк, затем структуру двух-трёх самых заполненных. Не выбирай колонки с паролями '
        . 'и токенами. Когда хватит данных — вызови finish и положи в result отчёт в Markdown: '
        . 'общее число таблиц, топ-5 по строкам, назначение базы одной фразой, '
        . 'краткое описание изученных таблиц.',
    maxRounds: 10,
    parallelToolCalls: false,
    hooks: new AgentHooks(
        onToolStart: static fn (string $name, array $args) => print(
            '  ' . $name . ': ' . mb_substr((string) ($args['query'] ?? '…'), 0, 100) . PHP_EOL
        ),
    ),
);

echo 'Изучаю базу ' . $database . '…' . PHP_EOL;

$report = $agent->ask('Составь отчёт по базе.');

// Слабые модели иногда отвечают текстом «теперь посмотрю…» и останавливаются.
// Признак готовности — вызов finish; если его нет, подталкиваем.
$finished = static fn (): bool => (array_slice($agent->trace(), -1)[0]['name'] ?? '') === 'finish';

for ($nudge = 1; $nudge <= 3 && !$finished(); $nudge++) {
    echo '  (модель остановилась без finish — продолжаем, попытка ' . $nudge . ')' . PHP_EOL;
    $report = $agent->ask('Продолжай по плану. Когда отчёт готов — вызови finish и положи его в result.');
}

$file = __DIR__ . '/var/db-report.md';
@mkdir(dirname($file), 0777, true);
file_put_contents($file, $report . PHP_EOL);

echo PHP_EOL . $report . PHP_EOL . PHP_EOL . 'сохранено: ' . $file . PHP_EOL;
