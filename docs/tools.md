# Инструменты

| Инструмент | Что делает | Как подключить |
|---|---|---|
| `get_time` | дата и время в часовом поясе | — |
| `calculator` | арифметика без `eval()` | — |
| `get_weather` | погода (open-meteo, без ключа) | — |
| `read_file` | чтение файла внутри корня | `new ReadFileTool($sandbox)` |
| `write_file` | создать/перезаписать файл | `new WriteFileTool($sandbox)` |
| `replace_in_file` | точечная замена фрагмента | `new ReplaceInFileTool($sandbox)` |
| `search_files` | поиск по содержимому (grep) | `new SearchFilesTool($sandbox)` |
| `find_files` | поиск файлов по маске | `new FindFilesTool($sandbox)` |
| `list_dir` | содержимое каталога | `new ListDirTool($sandbox)` |
| `csv_query` | выборка из CSV с фильтром | `new CsvQueryTool($sandbox)` |
| `json_query` | значение по пути из JSON | — |
| `http_request` | любой метод, тело, Basic/Bearer | — |
| `convert_units` | температура, длина, масса, скорость | — |
| `date_calc` | разница дат, «+30 days» | — |
| `uuid` | UUID v4 | — |
| `hash_text` | sha256 / sha1 / md5 | — |
| `finish` | завершение с результатом | `finishTool: 'finish'` |
| `run_command` | команды оболочки | `new ShellTool(workdir: ...)` |

Файловые инструменты работают только внутри явно заданного корня:

```php
$sandbox = new FileSandbox(__DIR__ . '/data');

$tools = (new ToolRegistry())->add(
    new ReadFileTool($sandbox),
    new WriteFileTool($sandbox),
    new ReplaceInFileTool($sandbox),
    new SearchFilesTool($sandbox),
    new FindFilesTool($sandbox),
    new ListDirTool($sandbox),
    new CsvQueryTool($sandbox),
);
```

- всё вне корня отвергается: `..`, абсолютные пути, симлинки наружу;
- запись атомарная: временный файл рядом и `rename`, полузаписанных файлов нет;
- `replace_in_file` требует **ровно одного** совпадения `search` — чтобы модель
  не заменила не тот кусок; для осознанной массовой замены есть `all: true`;
- поиск пропускает `.git`, `vendor`, `node_modules`, `var` и не читает бинарные
  файлы и файлы больше 1 МБ;
- большие файлы читаются через `offset` и `limit` в строках.

### Песочница: включить и выключить

По умолчанию всё ограничено корнем. Ограничение снимается осознанно — и
**отдельно для каждого инструмента**, потому что каждый получает свой экземпляр:

```php
$root = __DIR__ . '/data';

$tools = (new ToolRegistry())->add(
    new ReadFileTool(FileSandbox::unrestricted()),  // читать где угодно
    new WriteFileTool(new FileSandbox($root)),      // писать только в data
);
```

- `new FileSandbox($root)` — только внутри корня (по умолчанию);
- `FileSandbox::unrestricted($root)` — абсолютные пути и `..` разрешены,
  корень нужен только как база для относительных путей;
- `$sandbox->isRestricted()` — проверить текущий режим.

Свободный режим — это фактически файловый `run_command`: модель сможет
прочитать `~/.ssh/id_rsa` или перезаписать системный файл. Включайте, только
если процесс и так ограничен контейнером или отдельным пользователем.

Запись — единственная деструктивная операция среди файловых инструментов.
Перед регистрацией `write_file`/`replace_in_file` стоит подумать о
`toolApproval`: хук может спросить человека или запретить запись вне нужных
каталогов.

`json_query` достаёт значение по простому пути — удобно после `http_request`,
когда ответ большой:

```php
(new JsonQueryTool())->handle([
    'json' => '{"data":{"items":[{"id":42}]}}',
    'path' => 'data.items[0].id',
]); // data.items[0].id = 42
```

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

Чего здесь нет осознанно: `sql_query`. Доступ к БД слишком зависит от проекта —
драйвер, схема, права. Нужен такой инструмент — напишите свой и дайте ему
read-only пользователя; в ядре такие права «по умолчанию» не выдаются.


## Команды оболочки

`run_command` — единственный инструмент, который может сделать на машине
что угодно. В реестр сам себя он не добавляет: регистрируйте осознанно.

```php
$tools->add(new ShellTool(
    workdir: __DIR__,       // где выполнять
    shell: 'auto',          // auto | bash | sh | cmd | powershell | pwsh
    timeout: 10.0,          // секунды до принудительного завершения
    maxOutput: 16000,       // предел stdout/stderr
    allow: ['git', 'php'],  // необязательный allowlist по первому слову
    tmpDir: null,           // куда писать вывод; null — системный temp
));
```

Что делает:

- каждый вызов — новый процесс: `cd` и переменные между вызовами не живут;
- stdin закрыт, поэтому `vim`, `more`, `ssh` не зависнут;
- таймаут убивает процесс (на Windows — вместе с дочерними);
- при переполнении вывода команда прерывается, а не забивает диск;
- рабочий каталог фиксируется конструктором;
- авто-оболочка: `cmd` на Windows, `bash` на Unix; `powershell` и `pwsh`
  доступны явно, для них используется `-EncodedCommand` (кавычки не ломаются).

Чего не делает:

- **не изолирует**: команда имеет права процесса PHP;
- `allow` — грубый фильтр по первому слову, а не песочница:
  `php -r '...'` через него пройдёт;
- у `cmd` ограничение длины команды ~8 КБ.

Нужна настоящая изоляция — запускайте агента в контейнере, а не надейтесь
на фильтры внутри процесса.

В контейнерах системный `temp` часто недоступен — задайте `tmpDir` явно,
иначе инструмент вернёт «Не удалось создать временные файлы».



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


