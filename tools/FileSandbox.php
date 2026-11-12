<?php

declare(strict_types=1);

namespace AiSdk\Tools;

/**
 * Ограничитель файловых инструментов: любой путь обязан лежать внутри корня.
 *
 * Чтение проверяется через realpath, запись — через realpath каталога:
 * иначе «..» и симлинки уводили бы за пределы корня.
 */
final class FileSandbox
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Корень файловой песочницы не существует: ' . $root);
        }

        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Абсолютный путь к существующему файлу или каталогу.
     *
     * @throws \RuntimeException если путь вне корня или не найден
     */
    public function resolve(string $path): string
    {
        $relative = $this->normalize($path);

        if ($relative === '') {
            return $this->root;
        }

        $real = realpath($this->root . DIRECTORY_SEPARATOR . $relative);

        if ($real === false) {
            throw new \RuntimeException(
                'Путь не найден: ' . $path . '. Нужен путь к файлу относительно корня, например src/app.php.'
            );
        }

        $this->assertInside($real);

        return $real;
    }

    /**
     * Абсолютный путь для записи. Каталог должен существовать (если не передан
     * createDirs), сам файл — нет.
     *
     * @throws \RuntimeException
     */
    public function resolveForWrite(string $path, bool $createDirs = false): string
    {
        $relative = $this->normalize($path);

        if ($relative === '') {
            throw new \RuntimeException('Не указан путь к файлу.');
        }

        $directory = dirname($relative);
        $name = basename($relative);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \RuntimeException('Некорректное имя файла.');
        }

        $directoryPath = $directory === '.' || $directory === ''
            ? $this->root
            : $this->root . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($directoryPath)) {
            if (!$createDirs) {
                throw new \RuntimeException('Каталог не существует: ' . $directory);
            }

            if (!@mkdir($directoryPath, 0777, true) && !is_dir($directoryPath)) {
                throw new \RuntimeException('Не удалось создать каталог: ' . $directory);
            }
        }

        $realDirectory = realpath($directoryPath);

        if ($realDirectory === false) {
            throw new \RuntimeException('Каталог недоступен: ' . $directory);
        }

        $this->assertInside($realDirectory);

        return $realDirectory . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Атомарная запись: сначала во временный файл рядом, потом rename.
     * Так при сбое не остаётся полузаписанный файл.
     */
    public function writeAtomic(string $absolute, string $content): void
    {
        $directory = dirname($absolute);
        $temporary = @tempnam($directory, '.aisdk_');

        if ($temporary === false) {
            throw new \RuntimeException('Не удалось создать временный файл в ' . $directory);
        }

        $written = @file_put_contents($temporary, $content);

        if ($written === false) {
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

    /**
     * Путь для показа пользователю (относительно корня).
     */
    public function relative(string $absolute): string
    {
        if ($absolute === $this->root) {
            return '.';
        }

        $prefix = $this->root . DIRECTORY_SEPARATOR;

        if ($this->startsWithRoot($absolute)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($prefix)));
        }

        return $absolute;
    }

    /**
     * Относительный путь без «..», абсолютных путей и нулевых байтов.
     */
    private function normalize(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || $path === '.') {
            return '';
        }

        if (str_contains($path, "\0")) {
            throw new \RuntimeException('Некорректный путь.');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            throw new \RuntimeException('Абсолютные пути запрещены: указывайте путь относительно корня.');
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new \RuntimeException('Путь не должен содержать "..".');
        }

        return trim($path, '/');
    }

    private function assertInside(string $real): void
    {
        if ($real !== $this->root && !$this->startsWithRoot($real)) {
            throw new \RuntimeException('Путь выходит за пределы разрешённого каталога.');
        }
    }

    private function startsWithRoot(string $path): bool
    {
        $prefix = $this->root . DIRECTORY_SEPARATOR;

        // На Windows путь регистронезависим, на Unix — нет.
        return DIRECTORY_SEPARATOR === '\\'
            ? stripos($path, $prefix) === 0
            : str_starts_with($path, $prefix);
    }
}
