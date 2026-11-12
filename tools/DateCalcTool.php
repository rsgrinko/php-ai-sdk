<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Арифметика с датами: разница между датами и прибавление интервала.
 *
 * Даты — в формате ISO (2026-11-12, 2026-11-12 15:00:00) или "now".
 * Интервал — в формате PHP: "+30 days", "-2 weeks", "+3 hours", "last monday".
 */
final class DateCalcTool implements Tool
{
    public function name(): string
    {
        return 'date_calc';
    }

    public function description(): string
    {
        return 'Посчитать даты: разницу между двумя (from и to) или прибавить интервал '
            . '(from + modifier). Примеры modifier: "+30 days", "-2 weeks", "+3 hours". '
            . 'Даты в ISO или "now". Часовой пояс по умолчанию UTC.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Начальная дата или now.'],
                'to' => ['type' => 'string', 'description' => 'Конечная дата — если нужна разница.'],
                'modifier' => ['type' => 'string', 'description' => 'Интервал, например +30 days.'],
                'timezone' => ['type' => 'string', 'description' => 'IANA-пояс, по умолчанию UTC.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $timezone = trim((string) ($args['timezone'] ?? ''));
        $timezone = $timezone !== '' ? $timezone : 'UTC';

        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return 'Неизвестный часовой пояс: ' . $timezone . '.';
        }

        $fromRaw = trim((string) ($args['from'] ?? 'now'));
        $toRaw = trim((string) ($args['to'] ?? ''));
        $modifier = trim((string) ($args['modifier'] ?? ''));

        $from = $this->parse($fromRaw !== '' ? $fromRaw : 'now', $zone);

        if ($from === null) {
            return 'Не понял дату: ' . $fromRaw . '. Пример: 2026-11-12 или now.';
        }

        if ($toRaw !== '') {
            $to = $this->parse($toRaw, $zone);

            if ($to === null) {
                return 'Не понял дату: ' . $toRaw . '. Пример: 2026-11-12.';
            }

            return $this->diff($from, $to);
        }

        if ($modifier !== '') {
            $shifted = $from->modify($modifier);

            if ($shifted === false) {
                return 'Не понял интервал: ' . $modifier . '. Пример: +30 days.';
            }

            return $from->format('Y-m-d H:i:s T') . ' ' . $modifier . ' = '
                . $shifted->format('Y-m-d H:i:s T');
        }

        return 'Нужен либо to (разница), либо modifier (сдвиг).';
    }

    private function parse(string $value, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (strcasecmp($value, 'now') === 0) {
            return new \DateTimeImmutable('now', $zone);
        }

        // «!» сбрасывает незаданные части в ноль: без него PHP подставит
        // текущее время вместо 00:00:00.
        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:s', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value, $zone);

            if ($date !== false) {
                return $date;
            }
        }

        // Естественный язык: «next friday», «tomorrow».
        try {
            return new \DateTimeImmutable($value, $zone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function diff(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $interval = $from->diff($to);
        $sign = $to < $from ? ' (раньше)' : '';

        $days = (int) $interval->days;
        $hours = (int) $interval->h;
        $minutes = (int) $interval->i;

        return $from->format('Y-m-d H:i') . ' → ' . $to->format('Y-m-d H:i') . ': '
            . $days . ' ' . $this->pluralDays($days) . ', '
            . $hours . ' ч ' . $minutes . ' мин' . $sign;
    }

    private function pluralDays(int $days): string
    {
        $mod100 = $days % 100;
        $mod10 = $days % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'дней';
        }

        return match ($mod10) {
            1 => 'день',
            2, 3, 4 => 'дня',
            default => 'дней',
        };
    }
}
