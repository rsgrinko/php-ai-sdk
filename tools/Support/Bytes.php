<?php

declare(strict_types=1);

namespace AiSdk\Tools\Support;

/**
 * Человекочитаемый размер в байтах.
 */
final class Bytes
{
    public static function human(int $bytes): string
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
