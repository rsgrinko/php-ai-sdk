<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk;

/**
 * Диалог с моделью и цикл вызова инструментов.
 *
 * Ровно один публичный метод для работы:
 *   $agent->ask('вопрос');
 *
 * Внутри: отправили вопрос -> модель попросила инструменты -> выполнили ->
 * отправили результаты -> получили ответ. И так до maxRounds.
 *
 * Настройки — именованными аргументами конструктора:
 *
 *   new Agent(
 *       client: $client,
 *       tools: $tools,
 *       systemPrompt: '...',
 *       maxRounds: 5,              // предохранитель от зацикливания
 *       toolChoice: 'auto',        // auto | none | required | имя инструмента
 *       parallelToolCalls: true,   // разрешить модели батчить вызовы
 *       historyLimit: 40,          // сколько сообщений держать (0 — без лимита)
 *       toolApproval: fn ($name, $args) => $name !== 'drop_database',
 *       finishTool: 'finish',      // имя инструмента-финиша ('' — отключить)
 *   );
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

    /** @var list<array{name: string, arguments: array<string, mixed>, output: string, ms: int, blocked: bool}> */
    private array $trace = [];

    /** @var array{prompt_tokens: int, completion_tokens: int} */
    private array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];

    private string $systemPrompt;

    /**
     * @param string $toolChoice 'auto' — решает модель, 'none' — запретить вызов,
     *                           'required' — обязать вызвать хоть что-то,
     *                           иначе считается именем конкретного инструмента.
     * @param bool $parallelToolCalls если false, отправляется
     *                                parallel_tool_calls=false и модель не батчит вызовы.
     * @param int $historyLimit максимум сообщений в истории перед вопросом
     *                          (0 — не обрезать). System и текущий вопрос сохраняются.
     * @param (\Closure(string, array<string, mixed>): bool)|null $toolApproval
     *        хук перед выполнением инструмента. Вернул false — вызов запрещён,
     *        модель получит текст отказа. Удобно для подтверждения опасных действий.
     */
    public function __construct(
        private readonly ChatClient $client,
        private readonly ToolRegistry $tools = new ToolRegistry(),
        string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        private readonly int $maxRounds = 5,
        private readonly string $toolChoice = 'auto',
        private readonly bool $parallelToolCalls = true,
        private readonly int $historyLimit = 40,
        private readonly ?\Closure $toolApproval = null,
        private readonly string $finishTool = 'finish',
        private readonly ?AgentHooks $hooks = null,
    ) {
        $this->systemPrompt = $systemPrompt;
    }

    /**
     * Агент из переменных окружения AI_* (и .env, если он загружен).
     */
    public static function fromEnv(
        ToolRegistry $tools = new ToolRegistry(),
        string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        ?AgentHooks $hooks = null,
    ): self {
        return self::fromConfig(Config::fromEnv(), AiClient::fromEnv(), $tools, $systemPrompt, $hooks);
    }

    /**
     * Агент из массива — когда .env нет. В одном массиве и настройки
     * клиента (api_key, model, ...), и агента (max_rounds, ...): см. Config::ENV.
     *
     *   Agent::fromArray(['api_key' => 'sk-...', 'max_rounds' => 8], $tools);
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException если есть неизвестный ключ
     */
    public static function fromArray(
        array $config,
        ToolRegistry $tools = new ToolRegistry(),
        string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        ?AgentHooks $hooks = null,
    ): self {
        return self::fromConfig(Config::fromArray($config), AiClient::fromArray($config), $tools, $systemPrompt, $hooks);
    }

    /**
     * Агент, откуда бы ни пришли настройки: значение из массива,
     * недостающее — из окружения (.env), остальное — по умолчанию.
     *
     *   Agent::create([], $tools);                    // как fromEnv()
     *   Agent::create(['max_rounds' => 8], $tools);   // остальное из AI_*
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException если есть неизвестный ключ
     */
    public static function create(
        array $config = [],
        ToolRegistry $tools = new ToolRegistry(),
        string $systemPrompt = self::DEFAULT_SYSTEM_PROMPT,
        ?AgentHooks $hooks = null,
    ): self {
        return self::fromConfig(Config::resolve($config), AiClient::create($config), $tools, $systemPrompt, $hooks);
    }

    private static function fromConfig(
        Config $config,
        ChatClient $client,
        ToolRegistry $tools,
        string $systemPrompt,
        ?AgentHooks $hooks,
    ): self {
        return new self(
            client: $client,
            tools: $tools,
            systemPrompt: $systemPrompt,
            maxRounds: $config->int('max_rounds', 5),
            toolChoice: $config->string('tool_choice', 'auto'),
            parallelToolCalls: $config->bool('parallel_tool_calls', true),
            historyLimit: $config->int('history_limit', 40),
            finishTool: $config->string('finish_tool', 'finish'),
            hooks: $hooks,
        );
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
        $this->trimHistory();

        // tool_choice применяется только к первому запросу хода: иначе
        // 'required' заставит модель звать инструменты в каждом раунде и она
        // никогда не сможет дать ответ.
        $request = 1;
        $this->fireStep($request);
        $result = $this->chat(true);
        $round = 0;

        while ($result->hasToolCalls() && $round < $this->maxRounds) {
            $round++;

            // Сообщение ассистента с tool_calls обязательно: иначе API
            // не примет следующие за ним tool-сообщения.
            $this->messages[] = $result->assistantMessage;

            $finished = $this->runToolCalls($result->toolCalls);

            if ($finished !== null) {
                return $this->finish($finished);
            }

            // Остановку проверяем между раундами: недоделанных вызовов нет,
            // история остаётся валидной.
            if ($this->hooks?->stopRequested() === true) {
                return $this->finish('Остановлено пользователем.');
            }

            $this->fireStep(++$request);
            $result = $this->chat(false);
        }

        // Модель зациклилась на инструментах — просим ответить без них.
        if ($result->hasToolCalls()) {
            $this->messages[] = [
                'role' => 'user',
                'content' => 'Лимит вызовов инструментов исчерпан. Ответь на вопрос без инструментов.',
            ];

            // Без инструментов и без tool_choice: иначе API может отвергнуть запрос.
            $this->fireStep(++$request);
            $result = $this->send($this->messages, []);
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
     * Что реально вызывалось: имя, аргументы, результат, время, был ли отказ.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, output: string, ms: int, blocked: bool}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /**
     * Суммарный расход токенов за все запросы этого диалога.
     *
     * @return array{prompt_tokens: int, completion_tokens: int}
     */
    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * Начать диалог заново.
     */
    public function reset(): void
    {
        $this->messages = [];
        $this->trace = [];
        $this->usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    }

    /**
     * Выполнить один вызов инструмента (с учётом хука подтверждения)
     * и вернуть tool-сообщение для истории.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     * @return array<string, mixed>
     */
    private function runTool(array $call): array
    {
        $started = microtime(true);
        $blocked = false;

        $this->hooks?->toolStart($call['name'], $call['arguments']);

        if (!$this->approve($call['name'], $call['arguments'])) {
            $blocked = true;
            $output = 'Вызов инструмента "' . $call['name'] . '" отклонён оператором.';
        } else {
            $output = $this->tools->execute($call['name'], $call['arguments']);
        }

        $this->record($call, $output, (int) round((microtime(true) - $started) * 1000), $blocked);

        return [
            'role' => 'tool',
            'tool_call_id' => $call['id'],
            'content' => $output,
        ];
    }

    /**
     * Записать вызов в трассировку и сообщить хуку.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     */
    private function record(array $call, string $output, int $ms, bool $blocked): void
    {
        $step = [
            'name' => $call['name'],
            'arguments' => $call['arguments'],
            'output' => $output,
            'ms' => $ms,
            'blocked' => $blocked,
        ];

        $this->trace[] = $step;
        $this->hooks?->toolEnd($step);
    }

    /**
     * Выполнить пачку вызовов. Если среди них был finish — вернуть его
     * результат: ход на этом заканчивается.
     *
     * @param list<array{id: string, name: string, arguments: array<string, mixed>}> $calls
     */
    private function runToolCalls(array $calls): ?string
    {
        $finished = null;

        foreach ($calls as $call) {
            if ($finished !== null) {
                // Работа уже завершена, но API требует ответ на каждый
                // tool_call_id — иначе история станет невалидной.
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => 'Пропущено: работа уже завершена.',
                ];

                continue;
            }

            if ($this->isFinishCall($call)) {
                $this->hooks?->toolStart($call['name'], $call['arguments']);
                $finished = $this->finishResult($call);
                $this->record($call, $finished, 0, false);

                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => 'ok',
                ];

                continue;
            }

            $this->messages[] = $this->runTool($call);
        }

        return $finished;
    }

    /**
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     */
    private function isFinishCall(array $call): bool
    {
        return $this->finishTool !== ''
            && $call['name'] === $this->finishTool
            && $this->tools->has($this->finishTool);
    }

    /**
     * Достать финальный текст из аргументов finish.
     *
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     */
    private function finishResult(array $call): string
    {
        // result — как в схеме; остальные — на случай, если модель назовёт по-своему.
        foreach (['result', 'answer', 'summary', 'text', 'output'] as $key) {
            $value = $call['arguments'][$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function finish(string $result): string
    {
        $text = $result !== '' ? $result : '(модель завершила работу без результата)';
        $this->messages[] = ['role' => 'assistant', 'content' => $text];

        return $text;
    }

    private function fireStep(int $step): void
    {
        $this->hooks?->step($step, $this->maxRounds);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function approve(string $name, array $arguments): bool
    {
        try {
            return $this->toolApproval === null
                || ($this->toolApproval)($name, $arguments) === true;
        } catch (\Throwable) {
            // Упавший хук считаем запретом: безопаснее не выполнять действие.
            return false;
        }
    }

    /**
     * Обрезать историю до historyLimit сообщений.
     *
     * Режем целыми ходами: system и текущий вопрос остаются, а «осиротевшие»
     * tool-сообщения в начале недопустимы — API отвергнет такой запрос.
     */
    private function trimHistory(): void
    {
        if ($this->historyLimit <= 0) {
            return;
        }

        $count = count($this->messages);

        if ($count <= $this->historyLimit) {
            return;
        }

        $keepFrom = $count - $this->historyLimit;

        // Нельзя начинать с role:tool — у результата не будет своего вызова.
        while ($keepFrom < $count && ($this->messages[$keepFrom]['role'] ?? '') === 'tool') {
            $keepFrom++;
        }

        // Текущий вопрос (последний user) трогать нельзя.
        $lastUser = 0;

        foreach ($this->messages as $index => $message) {
            if (($message['role'] ?? '') === 'user') {
                $lastUser = $index;
            }
        }

        if ($keepFrom > $lastUser) {
            $keepFrom = $lastUser;
        }

        if ($keepFrom <= 0) {
            return;
        }

        $head = [];

        if (($this->messages[0]['role'] ?? '') === 'system') {
            $head[] = $this->messages[0];
        }

        $this->messages = array_merge($head, array_slice($this->messages, $keepFrom));
    }

    /**
     * @param bool $withToolChoice применять ли tool_choice (только первый
     *                             запрос хода, дальше — auto)
     * @return array<string, mixed>
     */
    private function chatOptions(bool $withToolChoice): array
    {
        $options = [];

        if ($withToolChoice && $this->toolChoice !== '' && $this->toolChoice !== 'auto') {
            $options['tool_choice'] = $this->toolChoice;
        }

        // Значение по умолчанию у провайдеров и так true — шлём только false.
        if (!$this->parallelToolCalls) {
            $options['parallel_tool_calls'] = false;
        }

        return $options;
    }

    private function chat(bool $withToolChoice = false): ChatResult
    {
        return $this->send(
            $this->messages,
            $this->tools->definitions(),
            $this->chatOptions($withToolChoice),
        );
    }

    /**
     * Один запрос к модели с накоплением расхода токенов.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed> $options
     */
    private function send(array $messages, array $tools, array $options = []): ChatResult
    {
        $result = $this->client->chat($messages, $tools, $options);

        $this->usage['prompt_tokens'] += (int) ($result->usage['prompt_tokens'] ?? 0);
        $this->usage['completion_tokens'] += (int) ($result->usage['completion_tokens'] ?? 0);

        return $result;
    }
}


