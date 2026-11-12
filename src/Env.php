<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Чтение настроек из .env без единой зависимости.
 *
 * Формат: KEY=VALUE, пустые строки и # - комментарии, значение можно брать
 * в кавычки. Уже установленные переменные окружения не перезаписываются.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2) {
                $quote = $value[0];

                if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function float(string $key, float $default): float
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (float) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
