<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Набор инструментов: хранит их, отдаёт схемы модели и вызывает по имени.
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param int $maxResultChars обрезка результата, чтобы не раздувать контекст
     */
    public function __construct(private readonly int $maxResultChars = 4000)
    {
    }

    public function add(Tool ...$tools): self
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Схемы для поля "tools" запроса к модели.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * Выполнить инструмент по имени.
     *
     * Никогда не бросает исключение: любая проблема становится текстом,
     * который модель получит как результат вызова и сможет обойти.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(string $name, array $arguments): string
    {
        if (!isset($this->tools[$name])) {
            return 'Ошибка: неизвестный инструмент "' . $name . '".';
        }

        try {
            $result = $this->tools[$name]->handle($arguments);
        } catch (\Throwable $e) {
            return 'Ошибка инструмента "' . $name . '": ' . $e->getMessage();
        }

        if (mb_strlen($result) > $this->maxResultChars) {
            return mb_substr($result, 0, $this->maxResultChars) . '…';
        }

        return $result;
    }
}
