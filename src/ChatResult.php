<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Результат одного ответа модели.
 *
 * assistantMessage — ровно то, что нужно положить обратно в историю, чтобы
 * API принял следующие за ним tool-сообщения.
 */
final class ChatResult
{
    /**
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     * @param array<string, mixed> $assistantMessage
     * @param array<string, int>   $usage
     * @param array<string, mixed> $raw полный ответ провайдера (для отладки)
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $assistantMessage,
        public readonly array $usage = [],
        public readonly array $raw = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}

