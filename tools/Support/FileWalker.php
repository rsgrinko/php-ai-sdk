<?php

declare(strict_types=1);

namespace AiSdk\Tools\Support;

/**
 * Рекурсивный обход каталога с единым списком игнорируемых папок.
 *
 * Исключения из колбэка пробрасываются наружу — так инструменты
 * останавливают обход по достижении лимита.
 */
final class FileWalker
{
    public const DEFAULT_IGNORE = ['.git', '.idea', '.vscode', 'vendor', 'node_modules', 'var'];

    /**
     * @param callable(string): void $onFile
     * @param list<string> $ignore
     */
    public static function walk(string $directory, callable $onFile, array $ignore = self::DEFAULT_IGNORE): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $ignore, true)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_dir($path)) {
                self::walk($path, $onFile, $ignore);

                continue;
            }

            if (is_file($path)) {
                $onFile($path);
            }
        }
    }
}
