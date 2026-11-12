<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\AtomicWriter;

/**
 * Точечная замена в файле: старый фрагмент -> новый.
 *
 * По умолчанию требует ровно одного совпадения: так модель не испортит файл,
 * заменив не тот кусок. Если совпадений несколько и это осознанно — all: true.
 */
final class ReplaceInFileTool implements Tool
{
    public function __construct(private readonly FileSandbox $sandbox)
    {
    }

    public function name(): string
    {
        return 'replace_in_file';
    }

    public function description(): string
    {
        return 'Заменить фрагмент текста в файле. Нужно ровно одно совпадение search, '
            . 'иначе замена не выполняется (если совпадений несколько — all: true). '
            . 'Пиши search вместе с отступами и соседними строками, чтобы попасть в нужное место.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Путь файла относительно корня.'],
                'search' => ['type' => 'string', 'description' => 'Что заменить: точный фрагмент.'],
                'replace' => ['type' => 'string', 'description' => 'На что заменить.'],
                'all' => [
                    'type' => 'boolean',
                    'description' => 'Заменить все совпадения. По умолчанию false.',
                ],
            ],
            'required' => ['path', 'search', 'replace'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $path = trim((string) ($args['path'] ?? ''));
        $search = (string) ($args['search'] ?? '');
        $replace = (string) ($args['replace'] ?? '');
        $all = (bool) ($args['all'] ?? false);

        if ($path === '') {
            return 'Не указан путь файла.';
        }

        if ($search === '') {
            return 'Пустой search: нечего заменять.';
        }

        try {
            $file = $this->sandbox->resolve($path);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        if (!is_file($file)) {
            return 'Это не файл: ' . $path;
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            return 'Не удалось прочитать файл.';
        }

        $count = substr_count($content, $search);

        if ($count === 0) {
            return 'Фрагмент не найден в ' . $path . '. Проверь отступы и текст.';
        }

        if ($count > 1 && !$all) {
            return 'Найдено совпадений: ' . $count . '. Уточни search или передай all: true.';
        }

        $updated = $all
            ? str_replace($search, $replace, $content)
            : $this->replaceOnce($content, $search, $replace);

        try {
            AtomicWriter::write($file, $updated);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return 'Заменено вхождений: ' . ($all ? $count : 1)
            . ', файл ' . $this->sandbox->relative($file)
            . ': ' . strlen($updated) . ' Б.';
    }

    /**
     * Заменить только первое вхождение, не трогая остальные.
     */
    private function replaceOnce(string $content, string $search, string $replace): string
    {
        $position = strpos($content, $search);

        if ($position === false) {
            return $content;
        }

        return substr_replace($content, $replace, $position, strlen($search));
    }
}

