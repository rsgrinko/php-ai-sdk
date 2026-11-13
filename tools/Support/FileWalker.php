<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools\Support;

/**
 * Рекурсивный обход каталога с единым списком игнорируемых папок.
 *
 * Колбэк возвращает false, чтобы остановить обход: это нужно инструментам
 * с лимитом результатов.
 */
final class FileWalker
{
    public const DEFAULT_IGNORE = ['.git', '.idea', '.vscode', 'vendor', 'node_modules', 'var'];

    /**
     * @param callable(string): bool $onFile вернуть false — остановить обход
     * @param list<string> $ignore
     * @return bool true — обошли всё; false — остановились по просьбе колбэка
     */
    public static function walk(string $directory, callable $onFile, array $ignore = self::DEFAULT_IGNORE): bool
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return true;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $ignore, true)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $name;

            if (is_dir($path)) {
                if (!self::walk($path, $onFile, $ignore)) {
                    return false;
                }

                continue;
            }

            if (is_file($path) && $onFile($path) === false) {
                return false;
            }
        }

        return true;
    }
}
