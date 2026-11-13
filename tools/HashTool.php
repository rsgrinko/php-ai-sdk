<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk\Tools;

use Rsgrinko\AiSdk\Tool;

/**
 * Хеш текста: sha256, sha1 или md5.
 */
final class HashTool implements Tool
{
    private const ALGOS = ['sha256', 'sha1', 'md5'];

    public function name(): string
    {
        return 'hash_text';
    }

    public function description(): string
    {
        return 'Посчитать хеш текста: sha256 (по умолчанию), sha1 или md5. Возвращает hex.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Текст для хеширования.'],
                'algo' => [
                    'type' => 'string',
                    'enum' => self::ALGOS,
                    'description' => 'Алгоритм, по умолчанию sha256.',
                ],
            ],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $text = (string) ($args['text'] ?? '');
        $algo = strtolower(trim((string) ($args['algo'] ?? 'sha256')));

        if ($algo === '') {
            $algo = 'sha256';
        }

        if (!in_array($algo, self::ALGOS, true)) {
            return 'Неизвестный алгоритм: ' . $algo . '. Доступно: ' . implode(', ', self::ALGOS) . '.';
        }

        return $algo . ': ' . hash($algo, $text);
    }
}
