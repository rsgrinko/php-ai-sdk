## Что внутри

- **`AiClient`** — один HTTP-вызов `/chat/completions` для DeepSeek, OpenAI,
  OpenRouter, Ollama, llama.cpp, LM Studio.
- **`Agent`** — цикл вызова инструментов, история диалога, трассировка.
- **`Tool` + `ToolRegistry`** — инструмент = 4 метода; реестр сам отдаёт
  схемы модели и вызывает нужный инструмент.
- **`ChatClient`** — интерфейс, чтобы подменить модель заглушкой в тестах.
- **`LoggingClient`** — декоратор: пишет каждый запрос, ответ, токены и время.
- **18 готовых инструментов**: время, калькулятор, погода, файлы (чтение,
  запись, поиск), CSV, HTTP, единицы, даты, UUID, хеши, JSON, явное завершение
  и команды оболочки — см. [таблицу](docs/tools.md).

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
  LoggingClient.php    декоратор: логи запросов и ответов
  Cost.php             оценка стоимости по токенам и тарифу
  Tool.php             интерфейс инструмента
  ToolRegistry.php     набор инструментов
  HttpTransport.php    низкоуровневый HTTP: методы, тело, лимит размера
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
  WriteFileTool.php    запись файла (атомарно)
  ReplaceInFileTool.php точечная замена фрагмента
  SearchFilesTool.php  поиск по содержимому
  FindFilesTool.php    поиск файлов по маске
  ListDirTool.php      список каталога внутри корня
  CsvQueryTool.php     выборка из CSV с фильтром
  JsonQueryTool.php    значение по пути из JSON
  HttpRequestTool.php  HTTP любого метода с авторизацией
  ShellTool.php        команды оболочки (опасно)
  ConvertUnitsTool.php температура, длина, масса, скорость
  DateCalcTool.php     разница дат и прибавление интервала
  UuidTool.php         UUID v4
  HashTool.php         sha256/sha1/md5
  Support/             помощники: Bytes, FileWalker, UrlGuard, AtomicWriter, Timeout
examples/
  01-chat.php          простой вопрос
  02-tools.php         агент с тремя инструментами
  03-custom-tool.php   свой инструмент с нуля
  04-cli.php           интерактивный чат в терминале
  05-settings.php      tool_choice, подтверждение вызовов, история
  06-finish.php        завершение через finish
  07-tools.php         единицы, даты, uuid, хеш
  08-shell.php         команды оболочки
  09-files.php         файлы и поиск
  10-http.php          HTTP: запрос, SSRF, allowPrivate
  11-json.php          инструменты без модели
  12-logging.php       логирование запросов к модели
  13-code-task.php     задача «с нуля»: код, тест, правка
tests/run.php          раннер тестов
tests/support.php      помощники, заглушки, FakeClient
tests/cases/           тесты по темам (agent, files, http, shell, ...)
docs/                  инструменты, примеры, провайдеры, справочник
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

## Логирование

`LoggingClient` — обычный декоратор над любым `ChatClient`:

```php
$client = new LoggingClient(
    inner: AiClient::fromEnv(),
    sink: fn (string $line) => error_log($line),  // null — error_log() по умолчанию
    verbose: true,                                 // печатать сообщения и инструменты
);

$agent = new Agent($client, $tools);
```

Один раунд выглядит так:

```
LLM → 2 сообщ., 2 инструментов
   [0] system: Отвечай кратко
   [1] user: Который час?
   инструменты: get_time, calculator
LLM ← tool_calls: get_time, calculator, 6084 мс, токены 377/44
```

Без `verbose` остаётся одна строка на запрос — этого достаточно для продакшена.
Ошибки пишутся строкой `LLM ✗` и **пробрасываются дальше**: декоратор ничего
не глотает.

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

## Документация

- [docs/tools.md](docs/tools.md) — инструменты, песочница, оболочка, свой инструмент
- [docs/examples.md](docs/examples.md) — все примеры: что показывают и какие флаги
- [docs/providers.md](docs/providers.md) — провайдеры и типовые ошибки подключения
- [docs/reference.md](docs/reference.md) — API, безопасность, чего здесь нет

## Тесты

```bash
php tests/run.php
```

100 проверок: калькулятор, время, реестр, обрезка результатов, весь цикл агента
(включая ошибку инструмента и лимит раундов), настройки, finish, файлы и
песочница (включая свободный режим), CSV, единицы, даты, uuid/хеши, сборка
HTTP-запроса, защита от SSRF, оболочка (код выхода, таймаут, allowlist), логирование.
Сеть не нужна — модель подменяется `FakeClient` из `tests/support.php`.

## Проверка без модели

Если хочется проверить весь цикл, не тратя токены:

```php
$agent = new Agent($fakeClient, $tools);   // ChatClient из tests/support.php
$agent->ask('вопрос');
```

---

Лицензия: делайте что хотите.




