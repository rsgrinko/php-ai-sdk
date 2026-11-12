<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Пример самого простого инструмента: без аргументов и без сети.
 */
final class TimeTool implements Tool
{
    private const WEEKDAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];

    public function name(): string
    {
        return 'get_time';
    }

    public function description(): string
    {
        return 'Текущие дата и время. Часовой пояс можно указать, если он важен.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'timezone' => [
                    'type' => 'string',
                    'description' => 'Часовой пояс IANA, например Europe/Moscow. По умолчанию Europe/Moscow.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $timezone = trim((string) ($args['timezone'] ?? ''));
        $timezone = $timezone !== '' ? $timezone : 'Europe/Moscow';

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
        } catch (\Throwable) {
            return 'Неизвестный часовой пояс: ' . $timezone . '. Пример: Europe/Moscow.';
        }

        $weekday = self::WEEKDAYS[(int) $now->format('N') - 1];

        return $now->format('Y-m-d H:i:s T') . ' (' . $weekday . ')';
    }
}
