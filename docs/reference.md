# Справочник

## Чего здесь сознательно нет

Это основа, а не фреймворк. Добавляется по месту, когда понадобится:

| Нужно | Куда добавить |
|---|---|
| Стриминг ответа | `stream: true` + чтение чанков (сложнее, отдельный метод) |
| Логирование запросов в БД | обёртка над `ChatClient` (декоратор), 20 строк |
| Память между запусками | сохранить `$agent->messages()` в JSON/БД |
| Стоимость и токены | уже есть в `ChatResult::$usage` |
| Ограничение частоты | обёртка над `ChatClient` по образцу логгера |
| Роли и права на инструменты | не отдавать часть `ToolRegistry` в `Agent` |

Декоратор — самый полезный приём. Пример логирования без правки SDK:

```php
final class LoggingClient implements ChatClient
{
    public function __construct(private ChatClient $inner) {}

    public function chat(array $messages, array $tools = [], array $options = []): ChatResult
    {
        $started = microtime(true);
        $result = $this->inner->chat($messages, $tools, $options);
        error_log(sprintf('LLM: %d мс, токенов %d', (microtime(true) - $started) * 1000, $result->usage['total_tokens'] ?? 0));

        return $result;
    }
}

$agent = new Agent(new LoggingClient(AiClient::fromEnv()), $tools);
```


## Безопасность

- **Инструмент выполняет ваш код.** Не давайте модели то, что не дали бы
  пользователю: `exec`, запись файлов, SQL, доступ к внутренней сети.
- **Валидируйте аргументы внутри `handle()`** — модель может прислать что
  угодно, включая `null` и лишние поля.
- **Обрезайте результаты** — в реестре есть лимит (`maxResultChars`), он
  защищает контекст от гигантских ответов.
- **Не отдавайте в `ToolRegistry` больше прав, чем нужно** конкретному сценарию.
- `calculator` считает сам, без `eval()` — не заменяйте на `eval`.

## Настройки: откуда берутся

`.env` не обязателен. Клиент и агент собираются четырьмя способами:

```php
// 1. Только окружение: переменные AI_* (и .env, если загружен через bootstrap.php или Env::load).
$client = AiClient::fromEnv();
$agent = Agent::fromEnv($tools);

// 2. Только массив — окружение не читается. Для констант, конфига фреймворка, БД.
$config = ['api_key' => MY_AI_KEY, 'model' => 'deepseek-chat', 'max_rounds' => 8];
$client = AiClient::fromArray($config);
$agent = Agent::fromArray($config, $tools);   // один массив и для клиента, и для агента

// 3. Смешанно: значение из массива, недостающее — из окружения, остальное — по умолчанию.
$agent = Agent::create(['model' => 'gpt-4o-mini'], $tools);   // ключ возьмётся из AI_API_KEY

// 4. Явно, конструктором — без всяких источников.
$client = new AiClient(apiKey: 'sk-...', model: 'deepseek-chat', verifySsl: true);
```

Неизвестный ключ в массиве — `InvalidArgumentException` со списком доступных:
опечатка (`modle`) не превращается молча в значение по умолчанию. Значения
можно передавать и строками (`'true'`, `'30'`), и нужного типа.

| Ключ массива | Переменная | По умолчанию | Что задаёт |
|---|---|---|---|
| `api_key` | `AI_API_KEY` | `''` | ключ API |
| `model` | `AI_MODEL` | `deepseek-chat` | модель |
| `base_url` | `AI_BASE_URL` | `https://api.deepseek.com` | адрес API без `/chat/completions` |
| `timeout` | `AI_TIMEOUT` | `30` | таймаут запроса, с |
| `temperature` | `AI_TEMPERATURE` | `0.7` | температура |
| `max_tokens` | `AI_MAX_TOKENS` | `1000` | лимит ответа |
| `disable_thinking` | `AI_DISABLE_THINKING` | `false` | DeepSeek: без «размышлений» |
| `verify_ssl` | `AI_VERIFY_SSL` | `false` | проверка TLS-сертификата |
| `ca_info` | `AI_CAINFO` | `''` | путь к CA-бандлу |
| `max_retries` | `AI_MAX_RETRIES` | `2` | повторы при 429/5xx |
| `max_rounds` | `AI_MAX_ROUNDS` | `5` | раундов инструментов за ход |
| `tool_choice` | `AI_TOOL_CHOICE` | `auto` | auto, none, required или имя инструмента |
| `parallel_tool_calls` | `AI_PARALLEL_TOOL_CALLS` | `true` | несколько вызовов за ответ |
| `history_limit` | `AI_HISTORY_LIMIT` | `40` | сообщений истории (0 — без лимита) |
| `finish_tool` | `AI_FINISH_TOOL` | `finish` | имя инструмента-финиша |

`http_request` тоже не зависит от окружения, если передать настройки явно:
`new HttpRequestTool(verifySsl: true, caInfo: '/path/cacert.pem')`.

## Шпаргалка по API

```php
// Клиент
$client = AiClient::fromEnv();
$result = $client->chat($messages, $tools = [], $options = []);
// options: tool_choice, parallel_tool_calls, temperature, max_tokens
$result->text; $result->toolCalls; $result->usage;

// Повторы при 429/5xx и обрыве связи (по умолчанию 2, AI_MAX_RETRIES)
$client = new AiClient($key, retry: new Retry(maxRetries: 3, baseDelay: 0.5, maxDelay: 30.0));
$client = new AiClient($key, retry: Retry::none());

// Расход и стоимость
$agent->usage();                         // [prompt_tokens, completion_tokens] за диалог
$cost = (new Cost())->withRate('model', input: 0.07, output: 0.28);
echo $cost->format($cost->estimate('model', $agent->usage()));

// Логирование поверх любого клиента
$client = new LoggingClient($client, sink: fn (string $l) => error_log($l), verbose: true);

// Агент
$agent = new Agent(
    client: $client,
    tools: $tools,
    systemPrompt: '...',
    maxRounds: 5,
    toolChoice: 'auto',          // auto | none | required | имя
    parallelToolCalls: true,
    historyLimit: 40,            // 0 — без обрезки
    toolApproval: fn ($name, $args): bool => true,
);
$agent->ask('вопрос');     // string
$agent->trace();           // [{name, arguments, output, ms, blocked}]
$agent->messages();        // вся история
$agent->setSystemPrompt('новый промпт'); // на ходу, история сохраняется
$agent->systemPrompt();    // текущий промпт
$agent->reset();

// Хуки хода
$agent = new Agent(
    client: $client,
    tools: $tools,
    hooks: new AgentHooks(
        onStep: fn (int $step, int $max) => print("шаг {$step}/{$max}\n"),
        onToolStart: fn (string $name, array $args) => print("→ {$name}\n"),
        onToolEnd: fn (array $s) => print('  ' . mb_substr($s['output'], 0, 60) . "\n"),
        shouldStop: fn (): bool => false,
    ),
);

// Агент целиком из .env
$agent = Agent::fromEnv($tools, 'Отвечай кратко.');

// Реестр
$registry = (new ToolRegistry($maxResultChars = 4000))->add($tool1, $tool2);
$registry->definitions();  // схемы для модели
$registry->execute($name, $args); // string, не бросает исключений

// Инструмент
final class MyTool implements Tool {
    public function name(): string;
    public function description(): string;
    public function parameters(): array;
    public function handle(array $args): string;
}

// Завершение: модель зовёт finish(result), Agent возвращает result
$tools->add(new FinishTool());
```





