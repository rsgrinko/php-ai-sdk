<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Минимальный контракт чата. Отдельный интерфейс нужен, чтобы в тестах
 * подставить фейковую модель и не ходить в сеть.
 */
interface ChatClient
{
    /**
     * @param list<array<string, mixed>> $messages история диалога
     * @param list<array<string, mixed>> $tools    определения в формате OpenAI
     */
    public function chat(array $messages, array $tools = []): ChatResult;
}
