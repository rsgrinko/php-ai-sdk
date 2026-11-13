<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\Bytes;
use AiSdk\Tools\Support\TextTable;

/**
 * Выборка из CSV-файла внутри песочницы.
 *
 * where: "колонка=значение" — точное совпадение (без регистра),
 *        "колонка~значение" — вхождение подстроки.
 */
final class CsvQueryTool implements Tool
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_ROWS = 100;
    private const CELL_MAX = 60;

    public function __construct(private readonly FileSandbox $sandbox)
    {
    }

    public function name(): string
    {
        return 'csv_query';
    }

    public function description(): string
    {
        return 'Прочитать CSV и вернуть строки таблицей. '
            . 'where — фильтр: "city=Тула" (точно) или "name~иван" (вхождение). '
            . 'columns — какие колонки вернуть через запятую. path — относительно корня.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Путь к CSV относительно корня.'],
                'where' => ['type' => 'string', 'description' => 'Фильтр: city=Тула или name~иван.'],
                'columns' => ['type' => 'string', 'description' => 'Список колонок через запятую.'],
                'limit' => ['type' => 'integer', 'description' => 'Сколько строк, по умолчанию 20, максимум ' . self::MAX_ROWS . '.'],
                'delimiter' => ['type' => 'string', 'description' => 'Разделитель, по умолчанию запятая.'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        $limit = (int) ($args['limit'] ?? 20);
        $limit = $limit < 1 ? 20 : min($limit, self::MAX_ROWS);
        $delimiter = (string) ($args['delimiter'] ?? ',');
        $delimiter = $delimiter !== '' ? $delimiter[0] : ',';

        try {
            [$header, $rows] = $this->read($path, $delimiter);
            $columns = $this->columnIndexes($header, (string) ($args['columns'] ?? ''));
            $filter = $this->parseWhere($header, (string) ($args['where'] ?? ''));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $table = [array_map(static fn (int $i): string => $header[$i], $columns)];

        foreach ($rows as $row) {
            if ($filter !== null && !$this->matches($row, $filter)) {
                continue;
            }

            $table[] = array_map(static fn (int $i): string => TextTable::cell($row[$i] ?? '', self::CELL_MAX), $columns);

            if (count($table) > $limit) {
                break;
            }
        }

        $found = count($table) - 1;

        if ($found === 0) {
            return 'Подходящих строк нет (всего строк: ' . count($rows) . ').';
        }

        return 'CSV ' . $path . ': ' . $found . ' из ' . count($rows) . " строк\n" . TextTable::render($table);
    }

    /**
     * Заголовок и строки файла.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     * @throws \RuntimeException
     */
    private function read(string $path, string $delimiter): array
    {
        $file = $this->sandbox->resolve($path);

        if (!is_file($file)) {
            throw new \RuntimeException('Это не файл: ' . $path);
        }

        $size = (int) filesize($file);

        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('CSV слишком большой: ' . Bytes::human($size) . ' (максимум 5 МБ).');
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть файл.');
        }

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        fclose($handle);

        $header = array_shift($rows);

        if ($header === null || $header === ['']) {
            throw new \RuntimeException('Не удалось прочитать заголовок CSV.');
        }

        return [$header, $rows];
    }

    /**
     * @param list<string> $header
     * @return list<int> индексы нужных колонок (пусто в запросе — все)
     * @throws \RuntimeException
     */
    private function columnIndexes(array $header, string $columns): array
    {
        if (trim($columns) === '') {
            return array_keys($header);
        }

        return array_map(
            fn (string $name): int => $this->columnIndex($header, trim($name)),
            explode(',', $columns),
        );
    }

    /**
     * @param list<string> $header
     * @return array{index: int, value: string, contains: bool}|null
     * @throws \RuntimeException
     */
    private function parseWhere(array $header, string $where): ?array
    {
        $where = trim($where);

        if ($where === '') {
            return null;
        }

        if (preg_match('/^([^=~]+)([=~])(.*)$/u', $where, $match) !== 1) {
            throw new \RuntimeException('Фильтр должен быть вида "колонка=значение" или "колонка~значение".');
        }

        return [
            'index' => $this->columnIndex($header, trim($match[1])),
            'value' => trim($match[3]),
            'contains' => $match[2] === '~',
        ];
    }

    /**
     * @param list<string> $header
     * @throws \RuntimeException
     */
    private function columnIndex(array $header, string $name): int
    {
        $index = array_search($name, $header, true);

        if ($index === false) {
            throw new \RuntimeException('Нет колонки "' . $name . '". Есть: ' . implode(', ', $header) . '.');
        }

        return (int) $index;
    }

    /**
     * @param list<string> $row
     * @param array{index: int, value: string, contains: bool} $filter
     */
    private function matches(array $row, array $filter): bool
    {
        $cell = $row[$filter['index']] ?? '';

        if ($filter['contains']) {
            return mb_stripos($cell, $filter['value']) !== false;
        }

        return mb_strtolower($cell) === mb_strtolower($filter['value']);
    }
}
