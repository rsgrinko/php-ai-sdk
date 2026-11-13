<?php

declare(strict_types=1);

namespace AiSdk\Tools\Support;

/**
 * Число для ответа модели: без хвостовых нулей и без экспоненты, пока это
 * возможно.
 *
 *   Number::format(4.0)      // "4"
 *   Number::format(1 / 3)    // "0.3333333333"
 *   Number::format(9 ** 30)  // "4.2391158275216E+28"
 */
final class Number
{
    public static function format(float $value, int $decimals = 10): string
    {
        // Огромные числа в int не помещаются: (int) 9 ** 30 даёт мусор.
        if (abs($value) >= 1e15) {
            return (string) $value;
        }

        $text = rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
