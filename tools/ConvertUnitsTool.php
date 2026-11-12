<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Перевод единиц: температура, длина, масса, скорость.
 *
 * Валют здесь нет осознанно: курс — это внешние изменяемые данные,
 * а не арифметика.
 */
final class ConvertUnitsTool implements Tool
{
    /** Коэффициенты к базовой единице (метры). */
    private const LENGTH = [
        'mm' => 0.001, 'cm' => 0.01, 'm' => 1.0, 'km' => 1000.0,
        'in' => 0.0254, 'ft' => 0.3048, 'yd' => 0.9144, 'mi' => 1609.344,
    ];

    /** Килограммы. */
    private const MASS = [
        'mg' => 0.000001, 'g' => 0.001, 'kg' => 1.0, 't' => 1000.0,
        'oz' => 0.028349523125, 'lb' => 0.45359237,
    ];

    /** Метры в секунду. */
    private const SPEED = [
        'mps' => 1.0, 'kmh' => 1 / 3.6, 'mph' => 0.44704, 'kn' => 0.514444444,
    ];

    /** Синонимы -> канонический код. */
    private const ALIASES = [
        'мм' => 'mm', 'см' => 'cm', 'м' => 'm', 'км' => 'km',
        'дюйм' => 'in', 'дюймы' => 'in', 'фут' => 'ft', 'футы' => 'ft', 'ярд' => 'yd', 'миля' => 'mi', 'мили' => 'mi',
        'мг' => 'mg', 'г' => 'g', 'кг' => 'kg', 'т' => 't', 'тонна' => 't', 'тонны' => 't',
        'унция' => 'oz', 'фунт' => 'lb', 'фунты' => 'lb',
        'м/с' => 'mps', 'км/ч' => 'kmh', 'узел' => 'kn', 'узлов' => 'kn',
        '°c' => 'c', 'celsius' => 'c', 'цельсий' => 'c', 'градус' => 'c',
        '°f' => 'f', 'fahrenheit' => 'f', 'фаренгейт' => 'f',
        '°k' => 'k', 'kelvin' => 'k', 'кельвин' => 'k',
        'метр' => 'm', 'метры' => 'm', 'километр' => 'km', 'километры' => 'km',
        'сантиметр' => 'cm', 'сантиметры' => 'cm', 'миллиметр' => 'mm', 'миллиметры' => 'mm',
        'грамм' => 'g', 'граммы' => 'g', 'килограмм' => 'kg', 'килограммы' => 'kg',
    ];

    public function name(): string
    {
        return 'convert_units';
    }

    public function description(): string
    {
        return 'Перевести величину между единицами. Температура: c, f, k. '
            . 'Длина: mm, cm, m, km, in, ft, yd, mi. Масса: mg, g, kg, t, oz, lb. '
            . 'Скорость: mps, kmh, mph, kn.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'number', 'description' => 'Число для перевода.'],
                'from' => ['type' => 'string', 'description' => 'Исходная единица, например km.'],
                'to' => ['type' => 'string', 'description' => 'Целевая единица, например mi.'],
            ],
            'required' => ['value', 'from', 'to'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $value = $args['value'] ?? null;

        if (!is_numeric($value)) {
            return 'value должно быть числом.';
        }

        $from = $this->normalize((string) ($args['from'] ?? ''));
        $to = $this->normalize((string) ($args['to'] ?? ''));

        if ($from === '' || $to === '') {
            return 'Укажи from и to, например from: km, to: mi.';
        }

        $value = (float) $value;

        $result = $this->convert($value, $from, $to);

        if ($result === null) {
            return 'Не знаю единицы "' . $from . '" или "' . $to . '". '
                . 'Доступно: c, f, k, mm, cm, m, km, in, ft, yd, mi, mg, g, kg, t, oz, lb, mps, kmh, mph, kn.';
        }

        return $this->format($value) . ' ' . $from . ' = ' . $this->format($result) . ' ' . $to;
    }

    private function convert(float $value, string $from, string $to): ?float
    {
        // Температура — не масштаб, а сдвиг, поэтому отдельно.
        $temperatures = ['c', 'f', 'k'];

        if (in_array($from, $temperatures, true) || in_array($to, $temperatures, true)) {
            if (!in_array($from, $temperatures, true) || !in_array($to, $temperatures, true)) {
                return null;
            }

            $celsius = match ($from) {
                'c' => $value,
                'f' => ($value - 32) * 5 / 9,
                'k' => $value - 273.15,
            };

            return match ($to) {
                'c' => $celsius,
                'f' => $celsius * 9 / 5 + 32,
                'k' => $celsius + 273.15,
            };
        }

        foreach ([self::LENGTH, self::MASS, self::SPEED] as $table) {
            if (isset($table[$from], $table[$to])) {
                return $value * $table[$from] / $table[$to];
            }
        }

        return null;
    }

    private function normalize(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));

        return self::ALIASES[$unit] ?? $unit;
    }

    private function format(float $value): string
    {
        if (abs($value - round($value)) < 1e-9) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
