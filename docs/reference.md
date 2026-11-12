# Справочник

## Чего здесь сознательно нет

Это основа, а не фреймворк. Добавляется по месту, когда понадобится:

| Нужно | Куда добавить |
|---|---|
| Повторы при 429/5xx | цикл в `AiClient::chat()` вокруг `post()` |
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


## Шпаргалка по API

```php
// Клиент
$client = AiClient::fromEnv();
$result = $client->chat($messages, $tools = [], $options = []);
// options: tool_choice, parallel_tool_calls, temperature, max_tokens
$result->text; $result->toolCalls; $result->usage;

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


