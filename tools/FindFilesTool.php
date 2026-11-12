<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Поиск файлов по маске имени внутри песочницы.
 */
final class FindFilesTool implements Tool
{
    private const IGNORED = ['.git', '.idea', '.vscode', 'vendor', 'node_modules', 'var'];

    public function __construct(
        private readonly FileSandbox $sandbox,
        private readonly int $defaultLimit = 100,
    ) {
    }

    public function name(): string
    {
        return 'find_files';
    }

    public function description(): string
    {
        return 'Найти файлы по маске имени: "*.php", "**/*.md", "Config*". '
            . 'Можно ограничить подкаталог. Возвращает пути и размеры.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Маска, например *.php или src/**/*.php.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Подкаталог для поиска. По умолчанию весь корень.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Сколько путей вернуть, по умолчанию ' . $this->defaultLimit . ', максимум 500.',
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
            return 'Пустая маска.';
        }

        $limit = (int) ($args['limit'] ?? $this->defaultLimit);
        $limit = max(1, min($limit, 500));

        try {
            $start = $this->sandbox->resolve((string) ($args['path'] ?? ''));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        if (!is_dir($start)) {
            return 'Это не каталог: ' . (string) ($args['path'] ?? '');
        }

        $found = [];
        $truncated = false;

        try {
            $this->eachEntry($start, function (string $path) use (&$found, $pattern, $limit): void {
                $relative = $this->sandbox->relative($path);

                if (!fnmatch($pattern, $relative) && !fnmatch($pattern, basename($path))) {
                    return;
                }

                $size = @filesize($path);
                $found[] = $relative . ' (' . $this->human($size === false ? 0 : $size) . ')';

                if (count($found) >= $limit) {
                    throw new \RuntimeException('__limit__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__limit__') {
                return 'Ошибка поиска: ' . $e->getMessage();
            }

            $truncated = true;
        }

        if ($found === []) {
            return 'Файлы не найдены по маске "' . $pattern . '".';
        }

        return 'Найдено ' . count($found) . ($truncated ? ' (обрезано по лимиту ' . $limit . ')' : '') . ":\n"
            . implode("\n", $found);
    }

    /**
     * @param callable(string): void $onFile
     */
    private function eachEntry(string $directory, callable $onFile): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || in_array($name, self::IGNORED, true)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_dir($path)) {
                $this->eachEntry($path, $onFile);

                continue;
            }

            if (is_file($path)) {
                $onFile($path);
            }
        }
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' МБ';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' КБ';
        }

        return $bytes . ' Б';
    }
}
