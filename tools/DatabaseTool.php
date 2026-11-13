<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\TextTable;

/**
 * SQL-запросы к базе данных через PDO (MySQL/MariaDB, а также SQLite и PostgreSQL).
 *
 *   // MySQL, только чтение (по умолчанию)
 *   $db = DatabaseTool::mysql('127.0.0.1', 'shop', 'reader', 'secret');
 *
 *   // полный доступ: INSERT, UPDATE, DELETE, DDL
 *   $db = DatabaseTool::mysql('127.0.0.1', 'shop', 'admin', 'secret', readOnly: false);
 *
 *   // готовое соединение
 *   $db = new DatabaseTool($pdo, readOnly: true);
 *
 * Режим «только чтение» держится на двух слоях:
 *   1. Проверка текста: один запрос, начинается с SELECT / SHOW / DESCRIBE /
 *      EXPLAIN / WITH, без INTO и исполняемых комментариев MySQL.
 *   2. Запрос выполняется в транзакции READ ONLY (в SQLite — PRAGMA query_only),
 *      после него — ROLLBACK. Это ловит запись, спрятанную в WITH ... DELETE
 *      или в хранимой функции.
 *
 * Самая надёжная защита — пользователь БД с одними правами SELECT: ни один
 * разбор SQL не заменит прав на сервере. Флаг readOnly — второй рубеж.
 *
 * Полный режим опасен: модель может удалить данные. Подключайте его к копии
 * базы или вместе с toolApproval в Agent.
 */
final class DatabaseTool implements Tool
{
    /** Первые слова, разрешённые в режиме только чтения. */
    private const READ_STATEMENTS = ['select', 'show', 'describe', 'desc', 'explain', 'with'];

    private const CELL_MAX = 100;
    private const OUTPUT_MAX = 20000;

    /**
     * @param \PDO $pdo соединение; режим ошибок будет переключён на исключения
     * @param bool $readOnly true — только чтение, false — любые запросы
     * @param int $maxRows сколько строк результата показать модели
     * @param string $name имя инструмента; поменяйте, если подключаете две базы
     * @param string $about что лежит в базе — подсказка модели, например
     *                      "Биллинг: таблицы users, payments, services"
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly bool $readOnly = true,
        private readonly int $maxRows = 50,
        private readonly string $name = 'sql_query',
        private readonly string $about = '',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Подключиться к MySQL/MariaDB с безопасными настройками: один запрос за
     * вызов, utf8mb4, таймаут подключения и выполнения запроса.
     *
     * @param float $timeout секунды на подключение и на выполнение SELECT
     * @throws \PDOException если подключиться не удалось
     */
    public static function mysql(
        string $host,
        string $database,
        string $user,
        string $password,
        int $port = 3306,
        bool $readOnly = true,
        float $timeout = 10.0,
        int $maxRows = 50,
        string $about = '',
    ): self {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => max(1, (int) ceil($timeout)),
            // «SELECT 1; DROP TABLE x» не пройдёт даже мимо проверок.
            self::mysqlAttribute('MULTI_STATEMENTS') => false,
        ]);

        // Ограничение времени запроса: MySQL — в миллисекундах, MariaDB — в
        // секундах. Какая из переменных есть, та и сработает.
        foreach ([
            'SET SESSION max_execution_time = ' . (int) round($timeout * 1000),
            'SET SESSION max_statement_time = ' . $timeout,
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException) {
            }
        }

        return new self($pdo, $readOnly, $maxRows, about: $about);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        $driver = $this->driver();
        $schema = $driver === 'sqlite'
            ? 'SELECT name, sql FROM sqlite_master'
            : 'SHOW TABLES и DESCRIBE <таблица>';

        $text = $this->readOnly
            ? 'Выполнить SQL-запрос к базе (' . $driver . ', только чтение: SELECT, SHOW, DESCRIBE, EXPLAIN). '
            : 'Выполнить SQL-запрос к базе (' . $driver . ', любые запросы, включая INSERT, UPDATE, DELETE). ';

        $text .= 'Один запрос за вызов. Структуру узнавай через ' . $schema . '. '
            . 'Возвращает до ' . $this->maxRows . ' строк — выбирай нужные колонки и добавляй LIMIT.';

        return $this->about !== '' ? $text . ' ' . $this->about : $text;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'SQL-запрос, например "SELECT id, name FROM users WHERE id = 5".',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $sql = trim((string) ($args['query'] ?? ''));

        if ($sql === '') {
            return 'Пустой запрос.';
        }

        $refusal = $this->check($sql);

        if ($refusal !== null) {
            return 'Запрос отклонён: ' . $refusal;
        }

        try {
            return $this->readOnly ? $this->runReadOnly($sql) : $this->run($sql);
        } catch (\PDOException $e) {
            return 'Ошибка SQL: ' . $e->getMessage();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Проверка текста запроса. Возвращает причину отказа или null.
     */
    private function check(string $sql): ?string
    {
        $executableComment = false;
        $code = self::stripLiterals($sql, $executableComment);

        if (str_contains(rtrim($code, " \t\r\n;"), ';')) {
            return 'один вызов — один запрос, без «;» между запросами.';
        }

        if (!$this->readOnly) {
            return null;
        }

        if ($executableComment) {
            return 'комментарии вида /*! ... */ MySQL выполняет как код — они запрещены.';
        }

        $first = strtolower((string) (preg_split('/[\s(]+/', ltrim($code), 2)[0] ?? ''));

        if (!in_array($first, self::READ_STATEMENTS, true)) {
            return 'доступ только на чтение, разрешены SELECT, SHOW, DESCRIBE, EXPLAIN, WITH.';
        }

        if (preg_match('/\binto\b/i', $code) === 1) {
            return 'INTO запрещён: он пишет в файл или переменную.';
        }

        return null;
    }

    /**
     * Убрать строки и комментарии, чтобы «;» или «DROP» внутри
     * 'текста' не принимались за код.
     */
    private static function stripLiterals(string $sql, bool &$executableComment): string
    {
        $pattern = '~\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|`[^`]*`|/\*.*?\*/|(?:--\s|#)[^\n]*~s';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $match) use (&$executableComment): string {
                if (str_starts_with($match[0], '/*!')) {
                    $executableComment = true;
                }

                return in_array($match[0][0], ["'", '"', '`'], true) ? "''" : ' ';
            },
            $sql,
        );
    }

    private function runReadOnly(string $sql): string
    {
        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException('Соединение уже в транзакции: режим только чтения не может её начать.');
        }

        $sqlite = $this->driver() === 'sqlite';

        if ($sqlite) {
            $this->pdo->exec('PRAGMA query_only = ON');
        }

        // Не beginTransaction(): нужен именно READ ONLY, а его PDO не умеет.
        $this->pdo->exec($sqlite ? 'BEGIN' : 'START TRANSACTION READ ONLY');

        try {
            return $this->run($sql);
        } finally {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
            }

            if ($sqlite) {
                $this->pdo->exec('PRAGMA query_only = OFF');
            }
        }
    }

    private function run(string $sql): string
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return 'Ошибка SQL: запрос не выполнен.';
        }

        try {
            if ($statement->columnCount() === 0) {
                return 'Готово. Затронуто строк: ' . $statement->rowCount() . '.';
            }

            return $this->table($statement);
        } finally {
            $statement->closeCursor();
        }
    }

    private function table(\PDOStatement $statement): string
    {
        $header = [];

        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            $header[] = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : 'col' . ($i + 1);
        }

        $rows = [$header];
        $more = false;

        while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
            if (count($rows) > $this->maxRows) {
                $more = true;
                break;
            }

            $rows[] = array_map(self::value(...), $row);
        }

        $count = count($rows) - 1;

        if ($count === 0) {
            return 'Строк нет. Колонки: ' . implode(', ', $header) . '.';
        }

        $title = $more
            ? 'Показаны первые ' . $count . ' строк, есть ещё — сузьте запрос или добавьте LIMIT.'
            : 'Строк: ' . $count . '.';

        $text = $title . "\n" . TextTable::render($rows);

        return strlen($text) > self::OUTPUT_MAX
            ? mb_strcut($text, 0, self::OUTPUT_MAX) . "\n… (обрезано, выберите меньше колонок)"
            : $text;
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $text = is_scalar($value) ? (string) $value : get_debug_type($value);

        if (!mb_check_encoding($text, 'UTF-8')) {
            return '(двоичные данные, ' . strlen($text) . ' байт)';
        }

        return TextTable::cell($text, self::CELL_MAX);
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * В PHP 8.4+ константы MySQL переехали в Pdo\Mysql, старые устарели.
     */
    private static function mysqlAttribute(string $name): int
    {
        return class_exists(\Pdo\Mysql::class)
            ? (int) constant(\Pdo\Mysql::class . '::ATTR_' . $name)
            : (int) constant('PDO::MYSQL_ATTR_' . $name);
    }
}
