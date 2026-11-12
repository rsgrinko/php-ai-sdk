<?php

declare(strict_types=1);

namespace AiSdk\Tools\Support;

/**
 * Таймаут действия, который может попросить сама модель (параметр timeout).
 *
 * Значение модели применяется как есть, но в пределах жёсткого максимума
 * инструмента: модель не может «повесить» процесс навсегда. Если она попросила
 * больше максимума — применяется максимум, и это видно в {@see self::clamped()}.
 */
final class Timeout
{
    private function __construct(
        public readonly float $seconds,
        public readonly ?float $requested,
        public readonly float $max,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments аргументы вызова от модели
     */
    public static function from(array $arguments, float $default, float $max): self
    {
        $max = max(1.0, $max);
        $raw = $arguments['timeout'] ?? null;
        $requested = is_numeric($raw) ? (float) $raw : null;

        return new self(max(1.0, min($max, $requested ?? $default)), $requested, $max);
    }

    /**
     * Модель попросила значение, которое пришлось урезать.
     */
    public function clamped(): bool
    {
        return $this->requested !== null && abs($this->requested - $this->seconds) > 0.001;
    }

    public static function parameterDescription(float $default, float $max): string
    {
        return sprintf(
            'Сколько секунд ждать (по умолчанию %s, максимум %s; больше — будет урезано).',
            self::format($default),
            self::format($max),
        );
    }

    public static function format(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.');
    }
}
