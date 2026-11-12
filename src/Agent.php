<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\TimeTool;

/**
 * Диалог с моделью и цикл вызова инструментов.
 *
 * Ровно один публичный метод для работы:
 *   $agent->ask('вопрос');
 *
 * Внутри: отправили вопрос -> модель попросила инструменты -> выполнили ->
 * отправили результаты -> получили ответ. И так до maxRounds.
 */
final class Agent
{
    private const DEFAULT_SYSTEM_PROMPT = <<<'TXT'
        Ты — полезный ассистент. Отвечай кратко и по делу.
        Если для ответа нужен факт (время, расчёт, погода и т.п.), сначала вызови подходящий инструмент.
        Не выдумывай данные, которые можно получить инструментом.
        TXT;

    /** @var list<array<string, mixed>> */
    private array $messages = [];

    /** @var list<array{name: string, arguments: array<string, mixed>, output: string, ms: int}> */
    private array $trace = [];

    private string $systemPrompt;

    public function __construct(
        private readonly ChatClient $client,
        private readonly ToolRegistry $tools = new ToolRegistry(),
        string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        private readonly int $maxRounds = 5,
    ) {
        $this->systemPrompt = $systemPrompt;
    }

    /**
     * Сменить системный промпт на ходу.
     *
     * Если диалог уже начат, обновляется первое system-сообщение — история
     * и инструменты при этом сохраняются. Если нет — промпт применится при
     * следующем ask().
     */
    public function setSystemPrompt(string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;

        if (($this->messages[0]['role'] ?? '') === 'system') {
            $this->messages[0]['content'] = $systemPrompt;
        }
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /**
     * Задать вопрос. История диалога сохраняется между вызовами.
     */
    public function ask(string $question): string
    {
        if ($this->messages === []) {
            $this->messages[] = ['role' => 'system', 'content' => $this->systemPrompt];
        }

        $this->messages[] = ['role' => 'user', 'content' => $question];

        $result = $this->chat();
        $round = 0;

        while ($result->hasToolCalls() && $round < $this->maxRounds) {
            $round++;

            // Сообщение ассистента с tool_calls обязательно: иначе API
            // не примет следующие за ним tool-сообщения.
            $this->messages[] = $result->assistantMessage;

            foreach ($result->toolCalls as $call) {
                $started = microtime(true);
                $output = $this->tools->execute($call['name'], $call['arguments']);

                $this->trace[] = [
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                    'output' => $output,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ];

                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => $output,
                ];
            }

            $result = $this->chat();
        }

        // Модель зациклилась на инструментах — просим ответить без них.
        if ($result->hasToolCalls()) {
            $this->messages[] = [
                'role' => 'user',
                'content' => 'Лимит вызовов инструментов исчерпан. Ответь на вопрос без инструментов.',
            ];

            $result = $this->client->chat($this->messages, []);
        }

        $text = $result->text !== '' ? $result->text : '(модель вернула пустой ответ)';
        $this->messages[] = ['role' => 'assistant', 'content' => $text];

        return $text;
    }

    /**
     * Вся история диалога — удобно сохранить в БД или отдать в свой лог.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Что реально вызывалось: имя, аргументы, результат, время.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, output: string, ms: int}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * Начать диалог заново.
     */
    public function reset(): void
    {
        $this->messages = [];
        $this->trace = [];
    }

    private function chat(): ChatResult
    {
        return $this->client->chat($this->messages, $this->tools->definitions());
    }

    /**
     * Быстрый старт: агент с инструментами по умолчанию.
     */
    public static function withDefaults(ChatClient $client): self
    {
        return new self(
            $client,
            (new ToolRegistry())->add(new TimeTool(), new CalculatorTool()),
        );
    }
}
