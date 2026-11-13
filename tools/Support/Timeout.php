<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools\Support;

/**
 * Таймаут, который может попросить сама модель (аргумент timeout).
 *
 * Значение модели применяется, но в пределах [1, max]: модель не может
 * «повесить» процесс навсегда.
 */
final class Timeout
{
    /**
     * @param array<string, mixed> $arguments аргументы вызова от модели
     */
    public static function seconds(array $arguments, float $default, float $max): float
    {
        $requested = $arguments['timeout'] ?? null;
        $seconds = is_numeric($requested) ? (float) $requested : $default;

        return max(1.0, min(max(1.0, $max), $seconds));
    }

    /**
     * Описание аргумента timeout для схемы инструмента.
     */
    public static function parameterDescription(float $default, float $max): string
    {
        return sprintf(
            'Сколько секунд ждать (по умолчанию %s, максимум %s; больше — будет урезано).',
            Number::format($default, 1),
            Number::format($max, 1),
        );
    }
}
