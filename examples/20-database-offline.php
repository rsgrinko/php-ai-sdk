<?php

declare(strict_types=1);

/**
 * Пример 20. DatabaseTool без модели: что увидит модель в ответ на запрос.
 *
 * Инструменты: sql_query (DatabaseTool) — вызывается напрямую через handle().
 * Флаги: readOnly, maxRows, name, about.
 *
 * Работает на SQLite в памяти: ни сервер БД, ни модель не нужны. Удобно,
 * чтобы посмотреть формат ответа и как режим «только чтение» отказывает.
 *
 * Запуск:
 *   php examples/20-database-offline.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Tools\DatabaseTool;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, client TEXT, city TEXT, total INTEGER, paid_at TEXT)');
$pdo->exec("INSERT INTO orders (client, city, total, paid_at) VALUES
    ('ООО Ромашка', 'Тула', 15000, '2026-11-01'),
    ('ИП Иванов', 'Москва', 3200, NULL),
    ('ООО Вектор', 'Тула', 9900, '2026-11-10'),
    ('АО Север', 'Казань', 44100, '2026-11-12')");

$show = static function (DatabaseTool $tool, string $sql): void {
    echo '>>> ' . $sql . PHP_EOL . $tool->handle(['query' => $sql]) . PHP_EOL . PHP_EOL;
};

// 1. Только чтение (по умолчанию).
$reader = new DatabaseTool($pdo, maxRows: 3, about: 'Таблица orders — заказы клиентов.');

echo '=== описание для модели ===' . PHP_EOL . $reader->description() . PHP_EOL . PHP_EOL;

$show($reader, "SELECT client, total FROM orders WHERE city = 'Тула'");
$show($reader, 'SELECT city, COUNT(*) AS n, SUM(total) AS sum FROM orders GROUP BY city ORDER BY sum DESC');
$show($reader, 'SELECT client, paid_at FROM orders WHERE paid_at IS NULL');
$show($reader, 'SELECT * FROM orders');          // больше maxRows — подсказка про LIMIT
$show($reader, 'SELECT nope FROM orders');       // ошибка SQL — текстом

echo '=== попытки записи в режиме только чтения ===' . PHP_EOL . PHP_EOL;

$show($reader, 'DELETE FROM orders');
$show($reader, 'SELECT 1; DROP TABLE orders');
$show($reader, '/* select */ UPDATE orders SET total = 0');
$show($reader, 'WITH x AS (SELECT 1) DELETE FROM orders');  // ловит транзакция READ ONLY

// 2. Полный доступ — отдельный экземпляр со своим именем.
$writer = new DatabaseTool($pdo, readOnly: false, name: 'sql_admin');

echo '=== полный доступ (' . $writer->name() . ') ===' . PHP_EOL . PHP_EOL;

$show($writer, "UPDATE orders SET paid_at = '2026-11-13' WHERE paid_at IS NULL");
$show($reader, 'SELECT COUNT(*) AS unpaid FROM orders WHERE paid_at IS NULL');
