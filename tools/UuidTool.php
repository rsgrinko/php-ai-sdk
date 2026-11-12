<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Генерация UUID v4.
 */
final class UuidTool implements Tool
{
    public function name(): string
    {
        return 'uuid';
    }

    public function description(): string
    {
        return 'Сгенерировать UUID версии 4 (случайный идентификатор).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $data = random_bytes(16);

        // Версия 4 и вариант RFC 4122.
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
