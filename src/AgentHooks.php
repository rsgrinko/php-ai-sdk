<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Колбэки хода работы агента.
 *
 * Нужны, чтобы интерфейс (CLI, веб, лог) видел, что происходит, в реальном
 * времени: номер шага, начало и конец инструмента, финальный ответ и запрос
 * на остановку. Любой колбэк можно не задавать.
 *
 *   $agent = new Agent(
 *       client: $client,
 *       tools: $tools,
 *       hooks: new AgentHooks(
 *           onToolStart: fn (string $name, array $args) => print("→ {$name}\n"),
 *           onToolEnd: fn (array $step) => print('  ' . mb_substr($step['output'], 0, 60) . "\n"),
 *       ),
 *   );
 *
 * Исключение внутри колбэка не ломает агента: оно проглатывается.
 */
final class AgentHooks
{
    /**
     * @param (\Closure(int, int): void)|null $onStep номер запроса к модели и предел раундов
     * @param (\Closure(string, array<string, mixed>): void)|null $onToolStart имя инструмента и аргументы
     * @param (\Closure(array{name: string, arguments: array<string, mixed>, output: string, ms: int, blocked: bool}): void)|null $onToolEnd
     *        запись трассировки после выполнения инструмента
     * @param (\Closure(string): void)|null $onAnswer финальный текст ответа
     * @param (\Closure(): bool)|null $shouldStop возвращает true — остановиться между раундами
     */
    public function __construct(
        public readonly ?\Closure $onStep = null,
        public readonly ?\Closure $onToolStart = null,
        public readonly ?\Closure $onToolEnd = null,
        public readonly ?\Closure $onAnswer = null,
        public readonly ?\Closure $shouldStop = null,
    ) {
    }

    public function step(int $step, int $max): void
    {
        $this->fire($this->onStep, $step, $max);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function toolStart(string $name, array $arguments): void
    {
        $this->fire($this->onToolStart, $name, $arguments);
    }

    /**
     * @param array{name: string, arguments: array<string, mixed>, output: string, ms: int, blocked: bool} $step
     */
    public function toolEnd(array $step): void
    {
        $this->fire($this->onToolEnd, $step);
    }

    public function answer(string $text): void
    {
        $this->fire($this->onAnswer, $text);
    }

    /**
     * Пользователь попросил остановиться. Проверяется между раундами,
     * чтобы история осталась согласованной.
     */
    public function stopRequested(): bool
    {
        if ($this->shouldStop === null) {
            return false;
        }

        try {
            return ($this->shouldStop)() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fire(?\Closure $hook, mixed ...$arguments): void
    {
        if ($hook === null) {
            return;
        }

        try {
            $hook(...$arguments);
        } catch (\Throwable) {
            // Колбэк логгера не должен ломать ответ агента.
        }
    }
}
