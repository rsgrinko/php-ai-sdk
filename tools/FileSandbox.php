<?php

declare(strict_types=1);

namespace AiSdk\Tools;

/**
 * Ограничитель файловых инструментов: любой путь обязан лежать внутри корня.
 *
 * Никакой «магии»: относительный путь склеивается с корнем, приводится к
 * реальному виду (realpath) и проверяется по префиксу. Всё, что выходит
 * наружу — «..», абсолютные пути, симлинки за пределы каталога — отвергается.
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
     * Привести относительный путь к абсолютному внутри корня.
     *
     * @throws \RuntimeException если путь вне корня или (для чтения) не найден
     */
    public function resolve(string $path, bool $mustExist = true): string
    {
        $path = trim($path);

        if ($path === '' || $path === '.') {
            return $this->root;
        }

        if (str_contains($path, "\0")) {
            throw new \RuntimeException('Некорректный путь.');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            throw new \RuntimeException('Абсолютные пути запрещены: указывайте путь относительно корня.');
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . $path;
        $real = realpath($candidate);

        if ($real === false) {
            if ($mustExist) {
                throw new \RuntimeException('Путь не найден: ' . $path);
            }

            return $candidate;
        }

        if ($real !== $this->root && !$this->startsWithRoot($real)) {
            throw new \RuntimeException('Путь выходит за пределы разрешённого каталога.');
        }

        return $real;
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

    private function startsWithRoot(string $path): bool
    {
        $prefix = $this->root . DIRECTORY_SEPARATOR;

        // На Windows путь регистронезависим, на Unix — нет.
        return DIRECTORY_SEPARATOR === '\\'
            ? stripos($path, $prefix) === 0
            : str_starts_with($path, $prefix);
    }
}
