# AI SDK — минимальная основа для чата с инструментами

Крошечная PHP-библиотека без зависимостей: диалог с любой OpenAI-совместимой
моделью и **function calling** (вызов инструментов).

```php
$agent = new Agent(AiClient::fromEnv(), $tools);
echo $agent->ask('Который час в Москве и сколько будет 17% от 4300?');
```

Модель сама решит, что ей нужен `get_time` и `calculator`, вызовет их,
получит результаты и ответит. Вам не нужно писать парсеры намерений.

---

## Что внутри

- **`AiClient`** — один HTTP-вызов `/chat/completions` для DeepSeek, OpenAI,
  OpenRouter, Ollama, llama.cpp, LM Studio.
- **`Agent`** — цикл вызова инструментов, история диалога, трассировка.
- **`Tool` + `ToolRegistry`** — инструмент = 4 метода; реестр сам отдаёт
  схемы модели и вызывает нужный инструмент.
- **`ChatClient`** — интерфейс, чтобы подменить модель заглушкой в тестах.
- **12 готовых инструментов**: время, калькулятор, погода, файлы, CSV, HTTP,
  единицы, даты, UUID, хеши и явное завершение — см. [таблицу](#инструменты).

Без Composer, без фреймворка, без БД. Только PHP и расширение `curl`.

## Требования

- PHP **8.1+** (проверено на 8.3)
- расширение `curl`
- ключ API (или локальная модель)

## Быстрый старт

```bash
# 1. Вписать ключ в .env
#    AI_API_KEY=sk-...

# 2. Проверить, что всё работает без сети
php tests/run.php

# 3. Первый запрос к модели
php examples/01-chat.php
```

Дальше — `php examples/02-tools.php`: там модель уже вызывает инструменты.

## Структура

```
bootstrap.php          автозагрузка + чтение .env
src/
  AiClient.php         клиент OpenAI-совместимого API
  ChatClient.php       интерфейс (для тестов)
  ChatResult.php       ответ модели: текст, tool_calls, usage
  Agent.php            цикл: вопрос → инструменты → ответ
  Tool.php             интерфейс инструмента
  ToolRegistry.php     набор инструментов
  Http.php             низкоуровневый HTTP: методы, тело, лимит размера
  HttpRequest.php      сборка запроса: query, body, заголовки, Basic/Bearer
  Env.php              .env без зависимостей
  AiException.php      ошибки
tools/
  TimeTool.php         время в часовом поясе
  CalculatorTool.php   арифметика без eval()
  WeatherTool.php      погода через open-meteo.com (без ключа)
  FinishTool.php       явное завершение работы с результатом
  FileSandbox.php      ограничитель путей (общий для файловых инструментов)
  ReadFileTool.php     чтение файла внутри корня
  ListDirTool.php      список каталога внутри корня
  CsvQueryTool.php     выборка из CSV с фильтром
  HttpRequestTool.php  HTTP любого метода с авторизацией
  ConvertUnitsTool.php температура, длина, масса, скорость
  DateCalcTool.php     разница дат и прибавление интервала
  UuidTool.php         UUID v4
  HashTool.php         sha256/sha1/md5
examples/
  01-chat.php          простой вопрос
  02-tools.php         агент с тремя инструментами
  03-custom-tool.php   свой инструмент с нуля
  04-cli.php           интерактивный чат в терминале
  05-settings.php      tool_choice, подтверждение вызовов, история
  06-finish.php        завершение через finish
  07-tools.php         единицы, даты, uuid, хеш
tests/run.php          тесты без сети
```

## Как это работает

```
вопрос
  ↓
Agent::ask()
  ↓  messages + схемы инструментов
AiClient::chat()  →  модель
  ↓
есть tool_calls?  ── нет ──→  финальный текст
  │ да
  ↓
ToolRegistry::execute(name, args)
  ↓  результат строкой
messages += {role: tool, tool_call_id, content}
  ↓
снова chat() ... (до maxRounds)
```

Ключевая деталь: сообщение ассистента с `tool_calls` **обязательно**
добавляется в историю перед результатами инструментов — иначе API отвергнет
запрос. Это уже сделано внутри `Agent`.

Ошибки инструментов не ломают диалог: `ToolRegistry` превращает исключение в
текст, модель видит `Ошибка инструмента "x": ...` и может исправиться.

## Настройки агента

Все параметры — именованными аргументами конструктора, defaults безопасные:

```php
$agent = new Agent(
    client: AiClient::fromEnv(),
    tools: $tools,
    systemPrompt: 'Отвечай кратко.',
    maxRounds: 5,             // предохранитель от зацикливания
    toolChoice: 'auto',       // auto | none | required | имя инструмента
    parallelToolCalls: true,  // разрешить модели батчить вызовы
    historyLimit: 40,         // сообщений истории (0 — без лимита)
    toolApproval: fn (string $name, array $args): bool => $name !== 'get_time',
);
```

| Параметр | `.env` | Что делает |
|---|---|---|
| `maxRounds` | `AI_MAX_ROUNDS` | сколько раундов «инструменты → модель» до принудительного ответа |
| `toolChoice` | `AI_TOOL_CHOICE` | `required` заставит вызвать инструмент, `none` запретит, имя — конкретный |
| `parallelToolCalls` | `AI_PARALLEL_TOOL_CALLS` | `false` — вызовы пойдут по одному |
| `historyLimit` | `AI_HISTORY_LIMIT` | обрезка старых ходов; `system` и текущий вопрос остаются |
| `toolApproval` | — | колбэк-подтверждение: `false` запрещает вызов, модель получает отказ |
| `finishTool` | `AI_FINISH_TOOL` | имя инструмента-финиша; `''` — отключить перехват |

Два нюанса, которые важно знать:

- **`toolChoice` действует только на первый запрос хода.** Иначе `required`
  заставит модель звать инструменты в каждом раунде, и она никогда не ответит.
  Дальше всегда `auto`.
- **`toolApproval` — не песочница.** Это удобный хук для «спросить человека» и
  для логов. Настоящая защита — не давать инструменту опасных прав вообще.

Агент целиком из `.env`:

```php
$agent = Agent::fromEnv($tools, 'Отвечай кратко.');
```

## Инструменты

| Инструмент | Что делает | Как подключить |
|---|---|---|
| `get_time` | дата и время в часовом поясе | — |
| `calculator` | арифметика без `eval()` | — |
| `get_weather` | погода (open-meteo, без ключа) | — |
| `read_file` | чтение файла внутри корня | `new ReadFileTool($sandbox)` |
| `list_dir` | содержимое каталога | `new ListDirTool($sandbox)` |
| `csv_query` | выборка из CSV с фильтром | `new CsvQueryTool($sandbox)` |
| `http_request` | любой метод, тело, Basic/Bearer | — |
| `convert_units` | температура, длина, масса, скорость | — |
| `date_calc` | разница дат, «+30 days» | — |
| `uuid` | UUID v4 | — |
| `hash_text` | sha256 / sha1 / md5 | — |
| `finish` | завершение с результатом | `finishTool: 'finish'` |

Файловые инструменты работают только внутри явно заданного корня:

```php
$sandbox = new FileSandbox(__DIR__ . '/data');

$tools = (new ToolRegistry())->add(
    new ReadFileTool($sandbox),
    new ListDirTool($sandbox),
    new CsvQueryTool($sandbox),
);
```

- всё вне корня отвергается: `..`, абсолютные пути, симлинки наружу;
- бинарные файлы не читаются, сообщается размер;
- большие файлы — через `offset` и `limit` в строках.

`http_request` — любой метод с параметрами и авторизацией:

```php
$tools->add(new HttpRequestTool(
    auth: [                              // креды по хосту, модель их не видит
        'api.example.com' => ['user' => 'bot', 'pass' => '***'],
        'other.example.com' => ['type' => 'bearer', 'token' => '***'],
    ],
));
```

Что умеет:

- методы `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`;
- `query` — добавляется к URL, кодируется по RFC 3986;
- `body` — объект уходит как JSON (или form при `content_type: form`),
  строка — как есть;
- `headers` — свои заголовки, кроме `Host`, `Content-Length`,
  `Transfer-Encoding`, `Connection` (их ставит cURL);
- `auth` аргументом — для разовых запросов; аргумент важнее карты из конструктора;
- `max_chars` — ограничение ответа.

Защита:

- только `http`/`https`, только публичные адреса: `localhost`, `10.x`,
  `172.16–31.x`, `192.168.x`, `169.254.x`, CGNAT запрещены;
- редиректы проверяются заново на каждом шаге; для `POST`/`PUT`/`DELETE`
  редирект не преследуется — возвращается `Location` и предложение повторить;
- размер ответа ограничен, запрос обрывается при превышении.

Нужен доступ к своим внутренним API — создайте инструмент с флагом:

```php
new HttpRequestTool(allowPrivate: true, auth: ['10.10.10.84' => ['user' => 'admin', 'pass' => '***']]);
```

Флаг снимает **все** проверки адреса, кроме схемы: инструмент станет ходить
куда угодно, включая `localhost`. Это осознанный компромисс для внутренних
сервисов; в публичном сценарии оставляйте `false`.

Осторожно с кредами в конструкторе: они добавляются к **каждому** запросу
на этот хост, включая редиректы внутри него. Если это нежелательно — передавайте
`auth` аргументом на конкретный вызов.

`convert_units` отвечает каноническими кодами (`5 км` → `5 km`), понимает
и русские названия единиц.

Чего здесь нет осознанно: `run_command` и `sql_query`. Если они нужны —
пишите свой инструмент с белым списком команд или read-only пользователем БД;
в ядре такие права «по умолчанию» не выдаются.

## Свой инструмент

Четыре метода. Всё.

```php
use AiSdk\Tool;

final class DiceTool implements Tool
{
    public function name(): string
    {
        return 'roll_dice';
    }

    public function description(): string
    {
        // Модель читает это и решает, когда звать инструмент.
        return 'Бросить кубик с указанным числом граней.';
    }

    public function parameters(): array
    {
        // JSON Schema, формат OpenAI function calling.
        return [
            'type' => 'object',
            'properties' => [
                'sides' => ['type' => 'integer', 'description' => 'Число граней, по умолчанию 6.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $sides = max(2, (int) ($args['sides'] ?? 6));

        return 'Выпало: ' . random_int(1, $sides);
    }
}
```

Регистрация:

```php
$tools = (new ToolRegistry())->add(new DiceTool(), new TimeTool());
$agent = new Agent(AiClient::fromEnv(), $tools);
```

Советы по описанию: пишите, **когда** инструмент нужен, а не только что он
делает. Модель выбирает по описанию, поэтому «верни курс валют» работает хуже,
чем «курс валют ЦБ на сегодня; вызывай, когда спрашивают про курс доллара».

## Завершение через finish

Обычно агент останавливается, когда модель вернула текст. Но текст — плохой
контракт: он приходит в свободной форме, и по нему не видно, закончила модель
работу или просто решила ответить.

`FinishTool` делает завершение явным: модель вызывает `finish(result: "...")`,
`Agent` видит это, останавливает цикл и возвращает `result` как ответ — без
лишнего запроса к модели.

```php
use AiSdk\Tools\FinishTool;

$agent = new Agent(
    client: $client,
    tools: (new ToolRegistry())->add(new FinishTool()),
    systemPrompt: 'Классифицируй обращение. Верни одну строку: категория; срочность.',
    toolChoice: 'required',   // ответ обязан прийти через finish
);

echo $agent->ask('«Не приходит SMS с кодом, второй день»');
// баг; высокая
```

Зачем это нужно:

- ответ всегда в известном месте — в аргументе `result`, а не в прозе;
- `toolChoice: 'required'` + один `finish` = гарантированный структурированный ответ;
- в `trace()` видно, что работа завершена именно вызовом, а не оборвалась;
- удобно для классификации, извлечения данных, «верни JSON» и подобного.

Работает и вместе с обычными инструментами: модель сначала считает
калькулятором, потом вызывает `finish` с результатом (пример
`examples/06-finish.php`).

Нюансы:

- перехват включается, только если `FinishTool` действительно зарегистрирован;
- имя настраивается: `finishTool: 'done'`, отключить — `finishTool: ''`;
- если модель не положила `result`, вернётся «(модель завершила работу без результата)»;
- `toolApproval` на finish не действует — это сигнал, а не действие.

## Провайдеры

Меняется только `.env`:

| Провайдер | `AI_BASE_URL` | `AI_MODEL` |
|---|---|---|
| DeepSeek | `https://api.deepseek.com` | `deepseek-chat` / `deepseek-flash` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |
| OpenRouter | `https://openrouter.ai/api/v1` | `anthropic/claude-3.5-sonnet` |
| Ollama | `http://localhost:11434/v1` | `qwen2.5:7b` |
| llama.cpp | `http://localhost:8080/v1` | `qwen3-8b` |

У Ollama и llama.cpp `AI_API_KEY` можно оставить пустым.

Не все локальные модели умеют function calling. Если инструменты не
вызываются — проверьте, что модель их поддерживает (qwen2.5/qwen3, llama 3.1+,
mistral-nemo и т.п.).

## Если не подключается

| Ошибка | Причина | Решение |
|---|---|---|
| `SSL certificate problem: unable to get local issuer certificate` | у PHP нет CA-бандла (обычно Windows) | `AI_CAINFO=C:\path\cacert.pem`; быстрый обход — `AI_VERIFY_SSL=false` |
| `Ошибка сети: Connection refused` | сервер не запущен или другой порт | проверить `AI_BASE_URL` (`curl http://host:8080/v1/models`) |
| `HTTP 401` | пустой или неверный ключ | `AI_API_KEY` |
| `HTTP 404` / `model not found` | модель не загружена | `AI_MODEL` = имя из `/v1/models` |
| `HTTP 400 unknown field 'thinking'` | провайдер не DeepSeek | `AI_DISABLE_THINKING=false` |
| Инструменты не вызываются | модель или сборка не умеют tools | qwen3 / llama 3.1+; llama.cpp должен быть с `--jinja` |

Проверить сервер до всякого кода:

```bash
curl http://10.10.10.77:8080/v1/models
```

## Тесты

```bash
php tests/run.php
```

65 проверок: калькулятор, время, реестр, обрезка результатов, весь цикл агента,
настройки (tool_choice, подтверждение вызовов, история), finish, файловая
песочница, CSV, единицы, даты, uuid/хеши, сборка HTTP-запроса (методы, тело,
заголовки, Basic/Bearer) и защита от SSRF
(успех, ошибка инструмента, лимит раундов, история). Сеть не нужна — модель
подменяется `FakeClient` из `tests/run.php`.

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

## Проверка без модели

Если хочется проверить весь цикл, не тратя токены:

```php
$agent = new Agent($fakeClient, $tools);   // ChatClient из tests/run.php
$agent->ask('вопрос');
```

---

Лицензия: делайте что хотите.
