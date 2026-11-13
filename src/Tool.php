<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk;

/**
 * Инструмент, который модель может вызвать.
 *
 * Четыре метода: имя, описание, схема аргументов и сама работа.
 * Ничего больше не требуется — реестр сам отдаст схему модели и вызовет handle().
 */
interface Tool
{
    /**
     * Имя латиницей, snake_case. Именно его модель указывает в tool_calls.
     */
    public function name(): string;

    /**
     * Что делает инструмент и когда его использовать.
     * Модель читает это описание и решает, звать ли инструмент.
     */
    public function description(): string;

    /**
     * JSON Schema аргументов в формате OpenAI function calling.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Выполнить инструмент и вернуть текст для модели.
     *
     * Бросать исключения не обязательно: ToolRegistry превратит их в текст
     * ошибки, и модель сможет исправиться.
     *
     * @param array<string, mixed> $args
     */
    public function handle(array $args): string;
}
