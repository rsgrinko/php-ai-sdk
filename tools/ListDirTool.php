<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools;

use Rsgrinko\AiSdk\Tool;
use Rsgrinko\AiSdk\Tools\Support\Bytes;

/**
 * Список файлов и каталогов внутри заданного корня.
 */
final class ListDirTool implements Tool
{
    private const MAX_ENTRIES = 200;

    public function __construct(private readonly FileSandbox $sandbox)
    {
    }

    public function name(): string
    {
        return 'list_dir';
    }

    public function description(): string
    {
        return 'Показать содержимое каталога: файлы, подкаталоги и размеры. '
            . 'Путь относительно корня; пустой — корень проекта.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Каталог относительно корня. По умолчанию корень.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $path = (string) ($args['path'] ?? '');

        try {
            $dir = $this->sandbox->resolve($path);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        if (!is_dir($dir)) {
            return 'Это не каталог: ' . $path;
        }

        $entries = @scandir($dir);

        if ($entries === false) {
            return 'Не удалось прочитать каталог.';
        }

        $entries = array_values(array_filter(
            $entries,
            static fn (string $name): bool => $name !== '.' && $name !== '..'
        ));

        sort($entries);

        $dirs = [];
        $files = [];

        foreach ($entries as $name) {
            $full = $dir . DIRECTORY_SEPARATOR . $name;

            if (is_dir($full)) {
                $dirs[] = $name . '/';

                continue;
            }

            $size = @filesize($full);
            $files[] = $name . ' (' . Bytes::human($size === false ? 0 : $size) . ')';
        }

        $all = array_merge($dirs, $files);
        $shown = array_slice($all, 0, self::MAX_ENTRIES);
        $title = $this->sandbox->relative($dir);

        $text = 'Каталог ' . $title . ': ' . count($all) . " объектов\n" . implode("\n", $shown);

        if (count($all) > count($shown)) {
            $text .= "\n…и ещё " . (count($all) - count($shown));
        }

        return $text;
    }
}
