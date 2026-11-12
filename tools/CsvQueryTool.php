<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

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
        $delimiter = (string) ($args['delimiter'] ?? ',');
        $delimiter = $delimiter !== '' ? $delimiter[0] : ',';

        if ($limit < 1) {
            $limit = 20;
        }

        $limit = min($limit, self::MAX_ROWS);

        try {
            $file = $this->sandbox->resolve($path);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        if (!is_file($file)) {
            return 'Это не файл: ' . $path;
        }

        $size = filesize($file);

        if ($size !== false && $size > self::MAX_BYTES) {
            return 'CSV слишком большой: ' . round($size / 1048576, 1) . ' МБ (максимум 5 МБ).';
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return 'Не удалось открыть файл.';
        }

        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false || $header === [null] || $header === []) {
            fclose($handle);

            return 'Не удалось прочитать заголовок CSV.';
        }

        $header = array_map(static fn ($name): string => trim((string) $name), $header);

        $requested = $this->requestedColumns($header, (string) ($args['columns'] ?? ''));

        if (is_string($requested)) {
            fclose($handle);

            return $requested;
        }

        $filter = $this->parseWhere($header, (string) ($args['where'] ?? ''));

        if (is_string($filter)) {
            fclose($handle);

            return $filter;
        }

        $allRows = $this->readRows($handle, $delimiter);
        fclose($handle);

        $total = count($allRows);
        $rows = $this->pickRows($allRows, $header, $requested, $filter, $limit);

        if ($rows === []) {
            return 'Подходящих строк нет (всего строк: ' . $total . ').';
        }

        $table = [array_map(static fn (int $i): string => $header[$i], $requested)];
        $table = array_merge($table, $rows);

        return 'CSV ' . $path . ': ' . count($rows) . ' из ' . $total . " строк\n" . $this->render($table);
    }

    /**
     * @param resource $handle
     * @return list<list<string>>
     */
    private function readRows($handle, string $delimiter): array
    {
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        return $rows;
    }

    /**
     * @param list<list<string>> $allRows
     * @param list<string> $header
     * @param list<int> $requested
     * @param array{index: int, value: string, contains: bool}|null $filter
     * @return list<list<string>>
     */
    private function pickRows(array $allRows, array $header, array $requested, ?array $filter, int $limit): array
    {
        $rows = [];

        foreach ($allRows as $row) {
            if ($filter !== null && !$this->matches($row, $header, $filter)) {
                continue;
            }

            $picked = [];

            foreach ($requested as $index) {
                $picked[] = $this->cell($row[$index] ?? '');
            }

            $rows[] = $picked;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }
    /**
     * @param list<string> $header
     * @return list<int>|string индексы колонок или текст ошибки
     */
    private function requestedColumns(array $header, string $columns): array|string
    {
        if (trim($columns) === '') {
            return array_keys($header);
        }

        $wanted = array_map('trim', explode(',', $columns));
        $indexes = [];

        foreach ($wanted as $name) {
            $index = array_search($name, $header, true);

            if ($index === false) {
                return 'Нет колонки "' . $name . '". Есть: ' . implode(', ', $header) . '.';
            }

            $indexes[] = (int) $index;
        }

        return $indexes;
    }

    /**
     * @param list<string> $header
     * @return array{index: int, value: string, contains: bool}|string|null
     */
    private function parseWhere(array $header, string $where): array|string|null
    {
        $where = trim($where);

        if ($where === '') {
            return null;
        }

        if (preg_match('/^([^=~]+)([=~])(.*)$/u', $where, $match) !== 1) {
            return 'Фильтр должен быть вида "колонка=значение" или "колонка~значение".';
        }

        $column = trim($match[1]);
        $index = array_search($column, $header, true);

        if ($index === false) {
            return 'Нет колонки "' . $column . '". Есть: ' . implode(', ', $header) . '.';
        }

        return [
            'index' => (int) $index,
            'value' => trim($match[3]),
            'contains' => $match[2] === '~',
        ];
    }

    /**
     * @param list<string> $row
     * @param list<string> $header
     * @param array{index: int, value: string, contains: bool} $filter
     */
    private function matches(array $row, array $header, array $filter): bool
    {
        $cell = $row[$filter['index']] ?? '';

        if ($filter['contains']) {
            return mb_stripos($cell, $filter['value']) !== false;
        }

        return mb_strtolower($cell) === mb_strtolower($filter['value']);
    }

    private function cell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return mb_strlen($value) > self::CELL_MAX
            ? mb_substr($value, 0, self::CELL_MAX) . '…'
            : $value;
    }

    /**
     * @param list<list<string>> $table
     */
    private function render(array $table): string
    {
        $widths = [];

        foreach ($table as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }

        $lines = [];

        foreach ($table as $rowIndex => $row) {
            $parts = [];

            foreach ($row as $index => $cell) {
                $parts[] = str_pad($cell, $widths[$index]);
            }

            $lines[] = implode(' | ', $parts);

            if ($rowIndex === 0) {
                $lines[] = implode('-+-', array_map(
                    static fn (int $width): string => str_repeat('-', $width),
                    $widths
                ));
            }
        }

        return implode("\n", $lines);
    }
}

