<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools;

/**
 * Ограничитель файловых инструментов.
 *
 * По умолчанию любой путь обязан лежать внутри корня: чтение проверяется через
 * realpath, запись — через realpath каталога. Иначе «..» и симлинки уводили бы
 * за пределы корня.
 *
 * Ограничение можно снять — осознанно и точечно:
 *
 *   new ReadFileTool(FileSandbox::unrestricted());          // читать везде
 *   new WriteFileTool(new FileSandbox(__DIR__ . '/data'));  // писать только в data
 *
 * Каждый инструмент получает свой экземпляр, поэтому политика задаётся
 * для каждого инструмента отдельно.
 */
final class FileSandbox
{
    private string $root;

    public function __construct(
        string $root,
        private readonly bool $restricted = true,
    ) {
        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new \InvalidArgumentException('Корень файловой песочницы не существует: ' . $root);
        }

        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Песочница без ограничений: абсолютные пути и «..» разрешены.
     * Корень нужен только как база для относительных путей.
     */
    public static function unrestricted(string $root = ''): self
    {
        $base = $root !== '' ? $root : (string) getcwd();

        return new self($base, false);
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    /**
     * Абсолютный путь к существующему файлу или каталогу.
     *
     * @throws \RuntimeException если путь вне корня (в ограниченном режиме) или не найден
     */
    public function resolve(string $path): string
    {
        $target = $this->absolutePath($path);
        $real = realpath($target);

        if ($real === false) {
            $hint = $this->restricted
                ? ' Нужен путь к файлу относительно корня, например src/app.php.'
                : '';

            throw new \RuntimeException('Путь не найден: ' . $path . '.' . $hint);
        }

        if ($this->restricted) {
            $this->assertInside($real);
        }

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
        $target = $this->absolutePath($path);
        $directory = dirname($target);
        $name = basename($target);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \RuntimeException('Некорректное имя файла.');
        }

        if (!is_dir($directory)) {
            if (!$createDirs) {
                throw new \RuntimeException('Каталог не существует: ' . $directory);
            }

            if (!@mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException('Не удалось создать каталог: ' . $directory);
            }
        }

        $realDirectory = realpath($directory) ?: $directory;

        if ($this->restricted) {
            $this->assertInside($realDirectory);
        }

        return $realDirectory . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Путь для показа пользователю (относительно корня, если внутри него).
     */
    public function relative(string $absolute): string
    {
        if ($absolute === $this->root) {
            return '.';
        }

        if (!$this->startsWithRoot($absolute)) {
            return $absolute;
        }

        return str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($absolute, strlen($this->root . DIRECTORY_SEPARATOR))
        );
    }

    /**
     * Привести путь к абсолютному. В ограниченном режиме абсолютные пути
     * и «..» запрещены; в свободном — разрешены.
     */
    private function absolutePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || $path === '.') {
            return $this->root;
        }

        if (str_contains($path, "\0")) {
            throw new \RuntimeException('Некорректный путь.');
        }

        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;

        if ($absolute) {
            if ($this->restricted) {
                throw new \RuntimeException('Абсолютные пути запрещены: указывайте путь относительно корня.');
            }

            return rtrim($path, '/');
        }

        if ($this->restricted && preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new \RuntimeException('Путь не должен содержать "..".');
        }

        return $this->root . DIRECTORY_SEPARATOR . $path;
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
