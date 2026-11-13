<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools;

use Rsgrinko\AiSdk\Tool;

/**
 * Достать значение из JSON по простому пути: data.items[0].id
 */
final class JsonQueryTool implements Tool
{
    private const MAX_OUTPUT = 2000;

    public function name(): string
    {
        return 'json_query';
    }

    public function description(): string
    {
        return 'Достать значение из JSON-строки по пути вида data.items[0].id '
            . '(точки для ключей, [n] для массивов). Удобно, когда ответ API большой и обрезан.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'json' => ['type' => 'string', 'description' => 'JSON-текст целиком.'],
                'path' => ['type' => 'string', 'description' => 'Путь, например data.items[0].id.'],
            ],
            'required' => ['json', 'path'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $json = (string) ($args['json'] ?? '');
        $path = trim((string) ($args['path'] ?? ''));

        if (trim($json) === '') {
            return 'Пустой JSON.';
        }

        if ($path === '') {
            return 'Пустой path.';
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Некорректный JSON: ' . json_last_error_msg();
        }

        try {
            $tokens = $this->tokenize($path);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $current = $data;
        $passed = [];

        foreach ($tokens as $token) {
            if (!is_array($current)) {
                return 'Путь ' . $path . ' не найден: на шаге "' . implode('.', $passed)
                    . '" значение не является объектом или массивом.';
            }

            if (array_key_exists($token, $current)) {
                $current = $current[$token];
                $passed[] = $token;

                continue;
            }

            if (ctype_digit($token) && array_key_exists((int) $token, $current)) {
                $current = $current[(int) $token];
                $passed[] = $token;

                continue;
            }

            return 'Путь ' . $path . ' не найден: нет '
                . (ctype_digit($token) ? 'элемента [' . $token . ']' : 'ключа "' . $token . '"') . '.';
        }

        if (is_scalar($current) || $current === null) {
            return $path . ' = ' . var_export($current, true);
        }

        $encoded = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return 'Значение не удалось сериализовать.';
        }

        $cut = mb_strlen($encoded) > self::MAX_OUTPUT;

        return $path . ' = ' . mb_substr($encoded, 0, self::MAX_OUTPUT) . ($cut ? '…' : '');
    }

    /**
     * data.items[0].id -> ['data', 'items', '0', 'id']. Ведущий "$." допускается.
     *
     * @return list<string>
     */
    private function tokenize(string $path): array
    {
        if (substr_count($path, '[') !== substr_count($path, ']')) {
            throw new \InvalidArgumentException('Не закрыта квадратная скобка в path.');
        }

        $tokens = preg_split('/[.\[\]]+/', ltrim(trim($path), '$'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            throw new \InvalidArgumentException('Пустой path.');
        }

        return $tokens;
    }
}
