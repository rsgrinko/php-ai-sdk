<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\Bytes;
use AiSdk\Tools\Support\FileWalker;

/**
 * Поиск по содержимому файлов внутри песочницы (аналог grep).
 *
 * Только чтение. Служебные каталоги пропускаются, большие и бинарные файлы
 * не сканируются.
 */
final class SearchFilesTool implements Tool
{
    private const MAX_FILE = 1048576;
    private const MAX_LINE = 200;

    public function __construct(
        private readonly FileSandbox $sandbox,
        private readonly int $defaultLimit = 50,
    ) {
    }

    public function name(): string
    {
        return 'search_files';
    }

    public function description(): string
    {
        return 'Найти строки по регулярному выражению в файлах проекта. '
            . 'Возвращает "путь:строка: текст". Можно ограничить подкаталог и маску файлов.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Регулярное выражение, например "class Kernel" или "TODO|FIXME".',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Подкаталог для поиска. По умолчанию весь корень.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Маска файлов, например *.php. По умолчанию все.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Сколько совпадений вернуть, по умолчанию ' . $this->defaultLimit . ', максимум 200.',
                ],
                'ignore_case' => [
                    'type' => 'boolean',
                    'description' => 'Игнорировать регистр. По умолчанию false.',
                ],
            ],
            'required' => ['pattern'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $pattern = trim((string) ($args['pattern'] ?? ''));

        if ($pattern === '') {
            return 'Пустой pattern.';
        }

        $limit = (int) ($args['limit'] ?? $this->defaultLimit);
        $limit = max(1, min($limit, 200));
        $glob = trim((string) ($args['glob'] ?? ''));
        $glob = $glob !== '' ? $glob : '*';
        $ignoreCase = (bool) ($args['ignore_case'] ?? false);

        $regex = $this->compile($pattern, $ignoreCase);

        if ($regex === null) {
            return 'Некорректное регулярное выражение: ' . $pattern;
        }

        try {
            $start = $this->sandbox->resolve((string) ($args['path'] ?? ''));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        if (!is_dir($start)) {
            return 'Это не каталог: ' . (string) ($args['path'] ?? '');
        }

        $results = [];

        FileWalker::walk($start, function (string $file) use (&$results, $regex, $glob, $limit): bool {
            $relative = $this->sandbox->relative($file);

            if (!fnmatch($glob, $relative) && !fnmatch($glob, basename($file))) {
                return true;
            }

            foreach ($this->matchLines($file, $regex) as $line) {
                $results[] = $relative . ':' . $line;

                if (count($results) >= $limit) {
                    return false; // лимит: останавливаем обход
                }
            }

            return true;
        });

        if ($results === []) {
            return 'Ничего не найдено по "' . $pattern . '".';
        }

        $truncated = count($results) >= $limit;

        return 'Найдено ' . count($results) . ($truncated ? ' (обрезано по лимиту ' . $limit . ')' : '') . ":\n"
            . implode("\n", $results);
    }

    private function compile(string $pattern, bool $ignoreCase): ?string
    {
        $delimiter = '~';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);
        $regex = $delimiter . $escaped . $delimiter . 'u' . ($ignoreCase ? 'i' : '');

        return @preg_match($regex, '') === false ? null : $regex;
    }

    /**
     * @return list<string> строки вида "12: текст"
     */
    private function matchLines(string $file, string $regex): array
    {
        $size = @filesize($file);

        if ($size === false || $size > self::MAX_FILE) {
            return [];
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        $head = (string) fread($handle, 4096);
        fclose($handle);

        if ($head !== '' && !mb_check_encoding($head, 'UTF-8')) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return [];
        }

        $matches = [];

        foreach ($lines as $index => $line) {
            if (@preg_match($regex, $line) !== 1) {
                continue;
            }

            $text = trim($line);

            if (mb_strlen($text) > self::MAX_LINE) {
                $text = mb_substr($text, 0, self::MAX_LINE) . '…';
            }

            $matches[] = ($index + 1) . ': ' . $text;
        }

        return $matches;
    }

    /**
     * @param callable(string): void $onFile
     */
}
