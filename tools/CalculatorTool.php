<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\Number;

/**
 * Арифметика без eval(): рекурсивный спуск по выражению.
 *
 * Приоритеты: + - < * / % < ^, унарный минус, скобки.
 * Так модель не ошибётся в счёте, а вы не отдадите ей eval().
 */
final class CalculatorTool implements Tool
{
    private string $source = '';
    private int $position = 0;

    public function name(): string
    {
        return 'calculator';
    }

    public function description(): string
    {
        return 'Точный расчёт арифметического выражения: + - * / % ^ и скобки. '
            . '% — остаток от деления, а не процент: 17% от 4300 считай как 0.17*4300. '
            . 'Используй вместо счёта в уме.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expression' => [
                    'type' => 'string',
                    'description' => 'Выражение, например (120+30)*0.15.',
                ],
            ],
            'required' => ['expression'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $expression = str_replace(' ', '', (string) ($args['expression'] ?? ''));

        if ($expression === '') {
            return 'Пустое выражение.';
        }

        if (strlen($expression) > 200) {
            return 'Слишком длинное выражение (максимум 200 символов).';
        }

        if (preg_match('/^[0-9+\-*\/%^().]+$/', $expression) !== 1) {
            return 'Разрешены только цифры, скобки и операции + - * / % ^.';
        }

        $this->source = $expression;
        $this->position = 0;

        try {
            $value = $this->parseExpression();
        } catch (\InvalidArgumentException $e) {
            return 'Ошибка в выражении: ' . $e->getMessage() . '.';
        }

        if ($this->position < strlen($this->source)) {
            return 'Ошибка в выражении: лишние символы с позиции ' . $this->position . '.';
        }

        if (!is_finite($value)) {
            return 'Результат не является числом (деление на ноль?).';
        }

        return $expression . ' = ' . Number::format($value);
    }

    private function parseExpression(): float
    {
        $value = $this->parseTerm();

        while (($operator = $this->peek()) === '+' || $operator === '-') {
            $this->position++;
            $right = $this->parseTerm();
            $value = $operator === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseTerm(): float
    {
        $value = $this->parsePower();

        while (in_array($this->peek(), ['*', '/', '%'], true)) {
            $operator = $this->source[$this->position];
            $this->position++;
            $right = $this->parsePower();

            if (($operator === '/' || $operator === '%') && $right == 0.0) {
                throw new \InvalidArgumentException('деление на ноль');
            }

            $value = match ($operator) {
                '*' => $value * $right,
                '/' => $value / $right,
                default => fmod($value, $right),
            };
        }

        return $value;
    }

    private function parsePower(): float
    {
        $value = $this->parseUnary();

        if ($this->peek() === '^') {
            $this->position++;

            // Правоассоциативно: 2^3^2 = 512.
            return $value ** $this->parsePower();
        }

        return $value;
    }

    private function parseUnary(): float
    {
        $char = $this->peek();

        if ($char === '-') {
            $this->position++;

            return -$this->parseUnary();
        }

        if ($char === '+') {
            $this->position++;

            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): float
    {
        if ($this->peek() === '(') {
            $this->position++;
            $value = $this->parseExpression();

            if ($this->peek() !== ')') {
                throw new \InvalidArgumentException('не закрыта скобка');
            }

            $this->position++;

            return $value;
        }

        // \G привязывает совпадение к текущей позиции.
        if (preg_match('/\G\d*\.?\d+/', $this->source, $match, 0, $this->position) !== 1) {
            throw new \InvalidArgumentException('ожидалось число с позиции ' . $this->position);
        }

        $this->position += strlen($match[0]);

        return (float) $match[0];
    }

    private function peek(): string
    {
        return $this->source[$this->position] ?? '';
    }
}
