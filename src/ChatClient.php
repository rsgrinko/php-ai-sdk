<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk;

/**
 * Минимальный контракт чата. Отдельный интерфейс нужен, чтобы в тестах
 * подставить фейковую модель и не ходить в сеть.
 */
interface ChatClient
{
    /**
     * @param list<array<string, mixed>> $messages история диалога
     * @param list<array<string, mixed>> $tools    определения в формате OpenAI
     * @param array<string, mixed> $options
     *        tool_choice         — 'auto' | 'none' | 'required' | имя функции
     *        parallel_tool_calls — разрешить модели батчить вызовы
     *        temperature, max_tokens — переопределить настройки клиента
     */
    public function chat(array $messages, array $tools = [], array $options = []): ChatResult;
}
