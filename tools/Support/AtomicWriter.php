<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools\Support;

/**
 * Атомарная запись файла: сначала во временный файл рядом, потом rename.
 *
 * Так при сбое не остаётся полузаписанный файл, а на Windows
 * существующий файл удаляется перед переименованием.
 */
final class AtomicWriter
{
    /**
     * @throws \RuntimeException если записать не удалось
     */
    public static function write(string $absolute, string $content): void
    {
        $directory = dirname($absolute);
        $temporary = @tempnam($directory, '.aisdk_');

        if ($temporary === false) {
            throw new \RuntimeException('Не удалось создать временный файл в ' . $directory);
        }

        if (@file_put_contents($temporary, $content) === false) {
            @unlink($temporary);

            throw new \RuntimeException('Не удалось записать файл.');
        }

        @chmod($temporary, 0644);

        // На Windows rename не перезаписывает существующий файл.
        if (is_file($absolute) && DIRECTORY_SEPARATOR === '\\') {
            @unlink($absolute);
        }

        if (!@rename($temporary, $absolute)) {
            @unlink($temporary);

            throw new \RuntimeException('Не удалось переименовать временный файл.');
        }
    }
}
