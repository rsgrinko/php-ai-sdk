<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Создание и перезапись файла внутри песочницы.
 *
 * Пишет целиком и атомарно. Для точечной правки лучше replace_in_file.
 */
final class WriteFileTool implements Tool
{
    private const MAX_BYTES = 1048576;

    public function __construct(private readonly FileSandbox $sandbox)
    {
    }

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Создать или перезаписать файл целиком (до 1 МБ). '
            . 'Путь относительно корня проекта. '
            . 'Для изменения одной строки используй replace_in_file, а не перезапись.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Путь файла относительно корня, например src/Config.php.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Полное содержимое файла.',
                ],
                'create_dirs' => [
                    'type' => 'boolean',
                    'description' => 'Создать недостающие каталоги. По умолчанию false.',
                ],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $path = trim((string) ($args['path'] ?? ''));
        $content = (string) ($args['content'] ?? '');
        $createDirs = (bool) ($args['create_dirs'] ?? false);

        if ($path === '') {
            return 'Не указан путь файла.';
        }

        if (strlen($content) > self::MAX_BYTES) {
            return 'Содержимое больше 1 МБ — запиши файл частями или сократи.';
        }

        try {
            $target = $this->sandbox->resolveForWrite($path, $createDirs);

            if (is_dir($target)) {
                return 'Это каталог, а не файл: ' . $path;
            }

            $existed = is_file($target);
            $this->sandbox->writeAtomic($target, $content);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return ($existed ? 'Перезаписан' : 'Создан')
            . ' файл ' . $this->sandbox->relative($target)
            . ': ' . strlen($content) . ' Б.';
    }
}
