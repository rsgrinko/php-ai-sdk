<?php

declare(strict_types=1);

// Тесты: database. SQLite в памяти — тот же PDO, сервер не нужен.

use AiSdk\Tools\DatabaseTool;
use AiSdk\Tools\Support\TextTable;

function demoDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, city TEXT, note TEXT)');
    $pdo->exec("INSERT INTO users (name, city, note) VALUES
        ('Анна', 'Тула', 'a;b'), ('Борис', 'Москва', NULL), ('Вера', 'Тула', 'DROP TABLE users')");

    return $pdo;
}

function userCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

test('sql_query: SELECT возвращает таблицу с заголовком', function (): void {
    $result = (new DatabaseTool(demoDb()))->handle(['query' => "SELECT name, city FROM users WHERE city = 'Тула'"]);

    assertContains('Строк: 2.', $result);
    assertContains("name | city\n-----+-----\nАнна | Тула", $result);
});

test('sql_query: NULL и пустой результат', function (): void {
    $tool = new DatabaseTool(demoDb());

    assertContains('Борис | NULL', $tool->handle(['query' => 'SELECT name, note FROM users WHERE id = 2']));
    assertSame('Строк нет. Колонки: id, name.', $tool->handle(['query' => 'SELECT id, name FROM users WHERE id = 99']));
});

test('sql_query: лимит строк и подсказка про LIMIT', function (): void {
    $result = (new DatabaseTool(demoDb(), maxRows: 2))->handle(['query' => 'SELECT id FROM users']);

    assertContains('Показаны первые 2 строк, есть ещё', $result);
    assertSame(false, str_contains($result, "\n3"));
});

test('sql_query: двоичные данные не попадают в ответ', function (): void {
    $result = (new DatabaseTool(demoDb()))->handle(['query' => "SELECT X'FFFE00' AS blob"]);

    assertContains('(двоичные данные, 3 байт)', $result);
});

test('sql_query: только чтение отклоняет запись и DDL', function (): void {
    $pdo = demoDb();
    $tool = new DatabaseTool($pdo);

    foreach ([
        "INSERT INTO users (name) VALUES ('x')",
        "UPDATE users SET name = 'x'",
        'DELETE FROM users',
        'DROP TABLE users',
        '/* select */ DELETE FROM users',
        "-- select\nDELETE FROM users",
        'SELECT 1; DELETE FROM users',
        'SELECT * FROM users INTO OUTFILE \'/tmp/x\'',
        'SELECT /*! 1; DROP TABLE users */ 1',
    ] as $sql) {
        assertContains('Запрос отклонён', $tool->handle(['query' => $sql]), $sql);
    }

    assertSame(3, userCount($pdo));
});

test('sql_query: «;» и «DROP» внутри строки — не код', function (): void {
    $tool = new DatabaseTool(demoDb());

    assertContains('Анна', $tool->handle(['query' => "SELECT name FROM users WHERE note = 'a;b';"]));
    assertContains('Вера', $tool->handle(['query' => "SELECT name FROM users WHERE note = 'DROP TABLE users'"]));
});

test('sql_query: запись внутри WITH ловит транзакция только для чтения', function (): void {
    $pdo = demoDb();
    $result = (new DatabaseTool($pdo))->handle(['query' => 'WITH x AS (SELECT 1) DELETE FROM users']);

    assertContains('Ошибка SQL', $result);
    assertSame(3, userCount($pdo));

    // После вызова соединение снова пишет: query_only снят, транзакция закрыта.
    $pdo->exec("INSERT INTO users (name) VALUES ('Глеб')");
    assertSame(4, userCount($pdo));
    assertSame(false, $pdo->inTransaction());
});

test('sql_query: только чтение не трогает чужую транзакцию', function (): void {
    $pdo = demoDb();
    $pdo->beginTransaction();

    $result = (new DatabaseTool($pdo))->handle(['query' => 'SELECT 1']);

    assertContains('уже в транзакции', $result);
    assertSame(true, $pdo->inTransaction());
    $pdo->rollBack();
});

test('sql_query: полный режим пишет и считает строки', function (): void {
    $pdo = demoDb();
    $tool = new DatabaseTool($pdo, readOnly: false);

    assertSame('Готово. Затронуто строк: 2.', $tool->handle(['query' => "UPDATE users SET city = 'Тверь' WHERE city = 'Тула'"]));
    assertSame('Готово. Затронуто строк: 1.', $tool->handle(['query' => "INSERT INTO users (name) VALUES ('Глеб')"]));
    assertSame(4, userCount($pdo));
    assertContains('Запрос отклонён: один вызов — один запрос', $tool->handle(['query' => 'DELETE FROM users; DROP TABLE users']));
    assertSame(4, userCount($pdo));
});

test('sql_query: ошибка SQL — текст для модели, а не исключение', function (): void {
    $result = (new DatabaseTool(demoDb()))->handle(['query' => 'SELECT nope FROM users']);

    assertContains('Ошибка SQL', $result);
    assertContains('nope', $result);
});

test('sql_query: описание зависит от режима и имени', function (): void {
    $read = new DatabaseTool(demoDb(), about: 'Таблица users — клиенты.');
    $full = new DatabaseTool(demoDb(), readOnly: false, name: 'billing_sql');

    assertContains('только чтение', $read->description());
    assertContains('sqlite_master', $read->description());
    assertContains('Таблица users — клиенты.', $read->description());
    assertContains('INSERT, UPDATE, DELETE', $full->description());
    assertSame('billing_sql', $full->name());
});

test('TextTable: кириллица выравнивается по символам', function (): void {
    $table = TextTable::render([['город', 'n'], ['Тула', '1'], ['Москва', '22']]);

    assertSame("город  | n\n-------+---\nТула   | 1\nМосква | 22", $table);
});
