<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools\Support;

/**
 * Текстовая таблица для ответа модели: первая строка — заголовок.
 *
 *   id | city
 *   ---+------
 *   1  | Тула
 *
 * Ширина считается в символах, а не в байтах: иначе кириллица разъезжается.
 */
final class TextTable
{
    /**
     * @param list<list<string>> $rows
     */
    public static function render(array $rows): string
    {
        $widths = [];

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, mb_strlen($cell));
            }
        }

        $lines = [];

        foreach ($rows as $rowIndex => $row) {
            $parts = [];

            foreach ($row as $index => $cell) {
                $parts[] = $cell . str_repeat(' ', $widths[$index] - mb_strlen($cell));
            }

            $lines[] = rtrim(implode(' | ', $parts));

            if ($rowIndex === 0) {
                $lines[] = implode('-+-', array_map(
                    static fn (int $width): string => str_repeat('-', $width),
                    $widths,
                ));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Ячейка в одну строку, не длиннее $max символов.
     */
    public static function cell(string $value, int $max): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
    }
}
