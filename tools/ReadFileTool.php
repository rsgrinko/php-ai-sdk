<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\Bytes;

/**
 * Чтение текстового файла внутри заданного корня.
 *
 * Умеет листать большие файлы: offset/limit в строках. Бинарные файлы
 * не читает — сообщает размер, чтобы модель не гадала.
 */
final class ReadFileTool implements Tool
{
    private const MAX_LINES = 2000;
    private const MAX_BYTES = 262144;

    public function __construct(private readonly FileSandbox $sandbox)
    {
    }

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Прочитать текстовый файл. Путь указывается относительно корня проекта. '
            . 'Для больших файлов задавай offset и limit в строках.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Путь к файлу относительно корня, например logs/bot.log.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'С какой строки начать (с нуля). По умолчанию 0.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Сколько строк вернуть, максимум ' . self::MAX_LINES . '. По умолчанию 200.',
                ],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $path = (string) ($args['path'] ?? '');
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $limit = (int) ($args['limit'] ?? 200);

        if ($limit < 1) {
            $limit = 200;
        }

        $limit = min($limit, self::MAX_LINES);

        try {
            $file = $this->sandbox->resolve($path);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        if (!is_file($file)) {
            return 'Это не файл: ' . $path;
        }

        $size = filesize($file);

        if ($size === false) {
            return 'Не удалось узнать размер файла.';
        }

        if ($size > 10 * 1024 * 1024) {
            return 'Файл слишком большой (' . Bytes::human($size) . '). Читай частями через offset и limit.';
        }

        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return 'Не удалось открыть файл.';
        }

        $first = fread($handle, 4096);
        fclose($handle);

        if ($first === false) {
            return 'Не удалось прочитать файл.';
        }

        if ($first !== '' && !mb_check_encoding($first, 'UTF-8')) {
            return 'Файл бинарный (' . Bytes::human($size) . '), не читаю.';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return 'Не удалось прочитать файл.';
        }

        $total = count($lines);
        $slice = array_slice($lines, $offset, $limit);

        if ($slice === []) {
            return 'В файле ' . $total . ' строк — диапазон пуст.';
        }

        $text = implode("\n", $slice);

        if (strlen($text) > self::MAX_BYTES) {
            $text = mb_strcut($text, 0, self::MAX_BYTES);
        }

        $from = $offset + 1;
        $to = $offset + count($slice);

        return 'Файл ' . $path . ', строки ' . $from . '–' . $to . ' из ' . $total . ":\n" . $text;
    }
}
