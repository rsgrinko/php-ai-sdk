<?php

declare(strict_types=1);

/**
 * Пример 21. Чат с базой в терминале: чтение сразу, запись — с подтверждением.
 *
 * Инструменты: sql_query (DatabaseTool, полный доступ).
 * Флаги: toolApproval — SELECT/SHOW/DESCRIBE/EXPLAIN выполняются сразу,
 *        всё остальное агент показывает и спрашивает «Выполнить? [y/N]».
 *
 * База: MySQL из DB_* в .env; если DB_HOST пуст — демо-SQLite в памяти.
 *
 * Запуск:
 *   php examples/21-database-chat.php
 *
 * Примеры вопросов:
 *   какие таблицы есть в базе?
 *   сколько записей в самой большой таблице?
 *   создай таблицу notes_demo с полями id и text и добавь туда запись «привет»
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\Env;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\DatabaseTool;

$host = Env::get('DB_HOST', '') ?? '';

if ($host !== '') {
    $db = DatabaseTool::mysql(
        host: $host,
        database: Env::get('DB_NAME', '') ?? '',
        user: Env::get('DB_USER', '') ?? '',
        password: Env::get('DB_PASSWORD', '') ?? '',
        port: Env::int('DB_PORT', 3306),
        readOnly: false,
    );
    echo 'База: MySQL ' . $host . PHP_EOL;
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, balance INTEGER)');
    $pdo->exec("INSERT INTO clients (name, balance) VALUES ('Ромашка', 1500), ('Вектор', -300), ('Север', 0)");
    $db = new DatabaseTool($pdo, readOnly: false);
    echo 'База: демо-SQLite (задайте DB_* в .env для MySQL)' . PHP_EOL;
}

// Чтение пропускаем, запись показываем человеку.
$confirm = static function (string $name, array $args): bool {
    $sql = trim((string) ($args['query'] ?? ''));

    if (preg_match('/^(select|show|describe|desc|explain)\b/i', $sql) === 1) {
        echo '  [sql] ' . $sql . PHP_EOL;

        return true;
    }

    echo PHP_EOL . '  Модель хочет выполнить:' . PHP_EOL . '    ' . $sql . PHP_EOL . '  Выполнить? [y/N] ';
    $answer = strtolower(trim((string) fgets(STDIN)));

    return in_array($answer, ['y', 'yes', 'д', 'да'], true);
};

$agent = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: (new ToolRegistry())->add($db),
    systemPrompt: 'Ты помощник по базе данных. Данные получай через sql_query, по одному запросу за вызов. '
        . 'Структуру узнавай сам. Если запрос отклонён пользователем — не повторяй его. Отвечай кратко.',
    maxRounds: 8,
    parallelToolCalls: false,
    toolApproval: $confirm,
);

echo 'Вопрос к базе (/reset — очистить историю, /quit — выход).' . PHP_EOL;

while (true) {
    echo PHP_EOL . '> ';
    $line = fgets(STDIN);

    if ($line === false || in_array(trim($line), ['/quit', '/exit'], true)) {
        break;
    }

    $line = trim($line);

    if ($line === '') {
        continue;
    }

    if ($line === '/reset') {
        $agent->reset();
        echo 'история очищена' . PHP_EOL;

        continue;
    }

    echo $agent->ask($line) . PHP_EOL;
}
