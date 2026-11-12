<?php

declare(strict_types=1);

/**
 * Тесты SDK: без сети, без модели и без API-ключа.
 *
 * Модель подменяется FakeClient — он отдаёт заранее заготовленные ответы.
 * Именно так удобно тестировать агентов в своих проектах.
 *
 * Запуск:
 *   php tests/run.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\ChatClient;
use AiSdk\ChatResult;
use AiSdk\Http;
use AiSdk\HttpRequest;
use AiSdk\Tool;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\ConvertUnitsTool;
use AiSdk\Tools\CsvQueryTool;
use AiSdk\Tools\DateCalcTool;
use AiSdk\Tools\FileSandbox;
use AiSdk\Tools\FinishTool;
use AiSdk\Tools\HashTool;
use AiSdk\Tools\HttpRequestTool;
use AiSdk\Tools\ListDirTool;
use AiSdk\Tools\ReadFileTool;
use AiSdk\Tools\TimeTool;
use AiSdk\Tools\UuidTool;

mb_internal_encoding('UTF-8');

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        $passed++;
        echo 'ok   ' . $name . PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $name . PHP_EOL . '     ' . $e->getMessage() . PHP_EOL;
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'ожидалось ' . var_export($expected, true)
            . ', получено ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : 'ожидалось true');
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'нет "' . $needle . '" в "' . $haystack . '"'
        );
    }
}

function assertThrows(callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message !== '' ? $message : 'ожидалось исключение');
}

/**
 * Модель-заглушка: отдаёт ответы по очереди и запоминает все запросы.
 */
final class FakeClient implements ChatClient
{
    /** @var list<array{messages: array<int, mixed>, tools: array<int, mixed>, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param list<ChatResult> $results
     */
    public function __construct(private array $results)
    {
    }

    public function chat(array $messages, array $tools = [], array $options = []): ChatResult
    {
        $this->requests[] = ['messages' => $messages, 'tools' => $tools, 'options' => $options];

        $result = array_shift($this->results);

        if ($result === null) {
            throw new RuntimeException('FakeClient: закончились заготовленные ответы');
        }

        return $result;
    }
}

/**
 * Ответ «вызови инструмент».
 *
 * @param array<string, mixed> $arguments
 */
function toolCall(string $name, array $arguments, string $id = 'call_1'): ChatResult
{
    $message = [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [[
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
            ],
        ]],
    ];

    return new ChatResult('', [['id' => $id, 'name' => $name, 'arguments' => $arguments]], $message);
}

function textResult(string $text): ChatResult
{
    return new ChatResult($text, [], ['role' => 'assistant', 'content' => $text]);
}

/**
 * Временный каталог для файловых инструментов.
 */
function testDir(): string
{
    $base = __DIR__ . '/tmp';

    if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
        throw new RuntimeException('не удалось создать tests/tmp');
    }

    $dir = $base . '/sandbox-' . bin2hex(random_bytes(3));

    if (!mkdir($dir, 0777, true)) {
        throw new RuntimeException('не удалось создать ' . $dir);
    }

    return $dir;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir) ?: [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;

        if (is_dir($path)) {
            removeDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * Ответ модели с несколькими вызовами сразу.
 *
 * @param list<array{name: string, arguments: array<string, mixed>, id: string}> $calls
 */
function toolCalls(array $calls): ChatResult
{
    $message = ['role' => 'assistant', 'content' => '', 'tool_calls' => []];
    $parsed = [];

    foreach ($calls as $call) {
        $message['tool_calls'][] = [
            'id' => $call['id'],
            'type' => 'function',
            'function' => [
                'name' => $call['name'],
                'arguments' => json_encode($call['arguments'], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $parsed[] = [
            'id' => $call['id'],
            'name' => $call['name'],
            'arguments' => $call['arguments'],
        ];
    }

    return new ChatResult('', $parsed, $message);
}

// ---------------------------------------------------------------------------
// Инструменты
// ---------------------------------------------------------------------------

test('calculator: приоритет операций', function (): void {
    assertSame('2+2*2 = 6', (new CalculatorTool())->handle(['expression' => '2+2*2']));
});

test('calculator: скобки и степень', function (): void {
    assertSame('(1+2)^2 = 9', (new CalculatorTool())->handle(['expression' => '(1+2)^2']));
});

test('calculator: деление даёт дробь', function (): void {
    assertSame('10/4 = 2.5', (new CalculatorTool())->handle(['expression' => '10/4']));
});

test('calculator: деление на ноль — понятная ошибка', function (): void {
    assertContains('деление на ноль', (new CalculatorTool())->handle(['expression' => '1/0']));
});

test('calculator: буквы не пропускаются', function (): void {
    assertContains('Разрешены только', (new CalculatorTool())->handle(['expression' => '2+a']));
});

test('time: возвращает дату в нужном поясе', function (): void {
    $result = (new TimeTool())->handle(['timezone' => 'Europe/Moscow']);

    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \S+ \((\p{L}{2})\)$/u', $result) === 1,
        'неожиданный формат: ' . $result
    );
});

test('time: неизвестный пояс — понятная ошибка', function (): void {
    assertContains('Неизвестный часовой пояс', (new TimeTool())->handle(['timezone' => 'Mars/Olympus']));
});

// ---------------------------------------------------------------------------
// Реестр инструментов
// ---------------------------------------------------------------------------

test('реестр: схемы в формате OpenAI tools', function (): void {
    $definitions = (new ToolRegistry())->add(new TimeTool(), new CalculatorTool())->definitions();

    assertSame(2, count($definitions));
    assertSame('function', $definitions[0]['type']);
    assertSame('get_time', $definitions[0]['function']['name']);
    assertSame('object', $definitions[0]['function']['parameters']['type']);
});

test('реестр: неизвестный инструмент не бросает исключение', function (): void {
    $result = (new ToolRegistry())->execute('nope', []);

    assertContains('неизвестный инструмент', $result);
});

test('реестр: исключение инструмента превращается в текст', function (): void {
    $broken = new class implements Tool {
        public function name(): string
        {
            return 'broken';
        }

        public function description(): string
        {
            return 'всегда падает';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => []];
        }

        public function handle(array $args): string
        {
            throw new RuntimeException('сломалось');
        }
    };

    $result = (new ToolRegistry())->add($broken)->execute('broken', []);

    assertContains('Ошибка инструмента "broken"', $result);
    assertContains('сломалось', $result);
});

test('реестр: длинный результат обрезается', function (): void {
    $verbose = new class implements Tool {
        public function name(): string
        {
            return 'verbose';
        }

        public function description(): string
        {
            return 'много текста';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => []];
        }

        public function handle(array $args): string
        {
            return str_repeat('x', 100);
        }
    };

    $result = (new ToolRegistry(10))->add($verbose)->execute('verbose', []);

    assertSame(11, mb_strlen($result), 'ожидалось 10 символов и многоточие');
});

// ---------------------------------------------------------------------------
// Агент
// ---------------------------------------------------------------------------

test('агент: вызывает инструмент и отдаёт финальный ответ', function (): void {
    $client = new FakeClient([
        toolCall('calculator', ['expression' => '2+2'], 'call_42'),
        textResult('Получилось 4.'),
    ]);

    $agent = new Agent($client, (new ToolRegistry())->add(new CalculatorTool()), 'ты тестовый', 3);

    $answer = $agent->ask('Сколько будет 2+2?');

    assertSame('Получилось 4.', $answer);
    assertSame(2, count($client->requests), 'должно быть два запроса к модели');

    // Второй запрос обязан содержать результат инструмента.
    $messages = $client->requests[1]['messages'];
    $last = $messages[count($messages) - 1];

    assertSame('tool', $last['role']);
    assertSame('call_42', $last['tool_call_id']);
    assertSame('2+2 = 4', $last['content']);

    // И сами схемы инструментов ушли модели.
    assertTrue($client->requests[0]['tools'] !== [], 'схемы инструментов не отправлены');

    // Трассировка для отладки.
    assertSame(1, count($agent->trace()));
    assertSame('calculator', $agent->trace()[0]['name']);
});

test('агент: ошибка инструмента уходит модели, а не в exception', function (): void {
    $client = new FakeClient([
        toolCall('nope', []),
        textResult('Не смог посчитать.'),
    ]);

    $agent = new Agent($client, new ToolRegistry(), 'ты тестовый', 3);
    $answer = $agent->ask('вопрос');

    assertSame('Не смог посчитать.', $answer);

    $messages = $client->requests[1]['messages'];
    $last = $messages[count($messages) - 1];

    assertContains('неизвестный инструмент', $last['content']);
});

test('агент: после лимита раундов просит ответ без инструментов', function (): void {
    $client = new FakeClient([
        toolCall('calculator', ['expression' => '1+1'], 'c1'),
        toolCall('calculator', ['expression' => '1+1'], 'c2'),
        toolCall('calculator', ['expression' => '1+1'], 'c3'),
        textResult('Два.'),
    ]);

    $agent = new Agent($client, (new ToolRegistry())->add(new CalculatorTool()), 'ты тестовый', 2);

    assertSame('Два.', $agent->ask('сколько?'));

    // 1 стартовый + 2 раунда + 1 финальный.
    assertSame(4, count($client->requests));

    $lastRequest = $client->requests[3];
    assertSame([], $lastRequest['tools'], 'финальный запрос должен идти без инструментов');
});

test('агент: помнит историю между вопросами', function (): void {
    $client = new FakeClient([
        textResult('Первый ответ.'),
        textResult('Второй ответ.'),
    ]);

    $agent = new Agent($client, new ToolRegistry(), 'ты тестовый', 3);

    $agent->ask('первый вопрос');
    $agent->ask('второй вопрос');

    $messages = $client->requests[1]['messages'];
    $roles = array_column($messages, 'role');

    assertSame(['system', 'user', 'assistant', 'user'], $roles);
    assertSame('Первый ответ.', $messages[2]['content']);
});

test('агент: системный промпт можно сменить на ходу', function (): void {
    $client = new FakeClient([textResult('первый'), textResult('второй')]);
    $agent = new Agent($client, new ToolRegistry(), 'промпт №1', 3);

    $agent->ask('вопрос');
    $agent->setSystemPrompt('промпт №2');
    $agent->ask('ещё вопрос');

    assertSame('промпт №2', $agent->systemPrompt());

    // System-сообщение остаётся первым и в истории, и в запросе к модели.
    $messages = $client->requests[1]['messages'];
    assertSame('system', $messages[0]['role']);
    assertSame('промпт №2', $messages[0]['content']);

    // История диалога при этом не потерялась.
    assertSame(4, count($messages), 'system + 2 user + assistant');
});

test('агент: без явного промпта берётся встроенный', function (): void {
    $agent = new Agent(new FakeClient([textResult('ок')]), new ToolRegistry());

    assertTrue($agent->systemPrompt() !== '', 'промпт по умолчанию пустой');
});

test('агент: tool_choice не навязывается на повторных раундах', function (): void {
    $client = new FakeClient([
        toolCall('calculator', ['expression' => '2+2'], 'c1'),
        textResult('4'),
    ]);

    $agent = new Agent(
        $client,
        (new ToolRegistry())->add(new CalculatorTool()),
        'тест',
        3,
        'required',
    );

    $agent->ask('посчитай');

    assertSame('required', $client->requests[0]['options']['tool_choice'] ?? null);
    assertTrue(
        !isset($client->requests[1]['options']['tool_choice']),
        'во втором раунде tool_choice должен вернуться к auto, иначе модель зациклится'
    );
});

// ---------------------------------------------------------------------------
// Настройки агента
// ---------------------------------------------------------------------------

test('настройки: tool_choice уходит в клиент', function (): void {
    $client = new FakeClient([textResult('ок')]);
    $agent = new Agent($client, new ToolRegistry(), 'тест', 3, 'required');

    $agent->ask('вопрос');

    assertSame('required', $client->requests[0]['options']['tool_choice'] ?? null);
});

test('настройки: tool_choice по умолчанию не навязывается', function (): void {
    $client = new FakeClient([textResult('ок')]);
    $agent = new Agent($client, new ToolRegistry(), 'тест', 3);

    $agent->ask('вопрос');

    assertTrue(!isset($client->requests[0]['options']['tool_choice']), 'auto не должен уходить явно');
});

test('настройки: parallel_tool_calls=false отправляется только когда выключено', function (): void {
    $off = new FakeClient([textResult('ок')]);
    (new Agent($off, new ToolRegistry(), 'тест', 3, 'auto', false))->ask('вопрос');

    assertSame(false, $off->requests[0]['options']['parallel_tool_calls']);

    $on = new FakeClient([textResult('ок')]);
    (new Agent($on, new ToolRegistry(), 'тест', 3, 'auto', true))->ask('вопрос');

    assertTrue(!isset($on->requests[0]['options']['parallel_tool_calls']), 'true — это значение по умолчанию');
});

test('настройки: хук может запретить вызов инструмента', function (): void {
    $client = new FakeClient([
        toolCall('calculator', ['expression' => '2+2'], 'c1'),
        textResult('Не смог: действие запрещено.'),
    ]);

    $agent = new Agent(
        $client,
        (new ToolRegistry())->add(new CalculatorTool()),
        'тест',
        3,
        'auto',
        true,
        40,
        static fn (string $name, array $args): bool => false,
    );

    $agent->ask('посчитай');

    assertTrue($agent->trace()[0]['blocked'], 'вызов должен быть помечен как отклонённый');
    assertContains('отклонён оператором', $agent->trace()[0]['output']);

    $messages = $client->requests[1]['messages'];
    $last = $messages[count($messages) - 1];
    assertContains('отклонён оператором', $last['content']);
});

test('настройки: хук пропускает разрешённый вызов', function (): void {
    $client = new FakeClient([
        toolCall('calculator', ['expression' => '2+2'], 'c1'),
        textResult('4'),
    ]);

    $seen = null;
    $agent = new Agent(
        $client,
        (new ToolRegistry())->add(new CalculatorTool()),
        'тест',
        3,
        'auto',
        true,
        40,
        static function (string $name, array $args) use (&$seen): bool {
            $seen = [$name, $args];

            return true;
        },
    );

    $agent->ask('посчитай');

    assertSame('calculator', $seen[0]);
    assertSame('2+2', $seen[1]['expression']);
    assertSame(false, $agent->trace()[0]['blocked']);
    assertSame('2+2 = 4', $agent->trace()[0]['output']);
});

test('настройки: historyLimit обрезает историю целыми ходами', function (): void {
    $client = new FakeClient([
        textResult('a1'),
        textResult('a2'),
        textResult('a3'),
        textResult('a4'),
    ]);

    $agent = new Agent($client, new ToolRegistry(), 'тест', 3, 'auto', true, 4);

    $agent->ask('вопрос 1');
    $agent->ask('вопрос 2');
    $agent->ask('вопрос 3');
    $agent->ask('вопрос 4');

    $messages = $agent->messages();

    assertSame('system', $messages[0]['role'], 'system должен оставаться первым');
    assertTrue(($messages[1]['role'] ?? '') !== 'tool', 'история не должна начинаться с tool');

    // Обрезка идёт Перед запросом: лимит 4 + system + финальный ответ.
    assertSame(6, count($messages), 'без лимита было бы 9');

    $last = $messages[count($messages) - 1];
    $question = $messages[count($messages) - 2];

    assertSame('user', $question['role']);
    assertSame('вопрос 4', $question['content'], 'текущий вопрос должен сохраниться');
    assertSame('assistant', $last['role']);
});

test('настройки: historyLimit=0 отключает обрезку', function (): void {
    $client = new FakeClient([textResult('a'), textResult('b')]);
    $agent = new Agent($client, new ToolRegistry(), 'тест', 3, 'auto', true, 0);

    $agent->ask('раз');
    $agent->ask('два');

    assertSame(5, count($agent->messages()), 'system + 2 вопроса + 2 ответа');
});

test('агент: reset очищает историю и трассировку', function (): void {
    $client = new FakeClient([textResult('ответ')]);
    $agent = new Agent($client, new ToolRegistry(), 'ты тестовый', 3);

    $agent->ask('вопрос');
    $agent->reset();

    assertSame([], $agent->messages());
    assertSame([], $agent->trace());
});

// ---------------------------------------------------------------------------
// Завершение через finish
// ---------------------------------------------------------------------------

test('finish: возвращает result и не делает лишний запрос', function (): void {
    $client = new FakeClient([
        toolCalls([['name' => 'finish', 'arguments' => ['result' => 'готово'], 'id' => 'f1']]),
    ]);

    $agent = new Agent(
        $client,
        (new ToolRegistry())->add(new FinishTool()),
        'ты тестовый',
        3,
    );

    assertSame('готово', $agent->ask('сделай'));
    assertSame(1, count($client->requests), 'после finish второго запроса быть не должно');
    assertSame('finish', $agent->trace()[0]['name']);

    $last = $agent->messages()[count($agent->messages()) - 1];
    assertSame('assistant', $last['role']);
    assertSame('готово', $last['content']);
});

test('finish: остальные вызовы из того же ответа получают ответ', function (): void {
    $client = new FakeClient([
        toolCalls([
            ['name' => 'calculator', 'arguments' => ['expression' => '2+2'], 'id' => 'c1'],
            ['name' => 'finish', 'arguments' => ['result' => '4'], 'id' => 'f1'],
        ]),
    ]);

    $agent = new Agent(
        $client,
        (new ToolRegistry())->add(new CalculatorTool(), new FinishTool()),
        'ты тестовый',
        3,
    );

    assertSame('4', $agent->ask('посчитай'));

    // История должна остаться валидной: у каждого tool_call_id есть tool-ответ.
    $messages = $agent->messages();
    $answered = [];

    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'tool') {
            $answered[] = $message['tool_call_id'];
        }
    }

    assertSame(['c1', 'f1'], $answered);
    assertSame('2+2 = 4', $messages[3]['content'], 'калькулятор должен выполниться до finish');
});

test('finish: без result возвращает понятную заглушку', function (): void {
    $client = new FakeClient([
        toolCalls([['name' => 'finish', 'arguments' => [], 'id' => 'f1']]),
    ]);

    $agent = new Agent($client, (new ToolRegistry())->add(new FinishTool()), 'тест', 3);

    assertContains('без результата', $agent->ask('сделай'));
});

test('finish: незарегистрированный инструмент не перехватывается', function (): void {
    $client = new FakeClient([
        toolCall('finish', ['result' => 'x'], 'f1'),
        textResult('продолжил'),
    ]);

    $agent = new Agent($client, new ToolRegistry(), 'тест', 3);

    assertSame('продолжил', $agent->ask('сделай'));
    assertContains('неизвестный инструмент', $agent->trace()[0]['output']);
});

test('finish: можно отключить перехват через finishTool', function (): void {
    $client = new FakeClient([
        toolCall('finish', ['result' => 'x'], 'f1'),
        textResult('обычный ответ'),
    ]);

    $agent = new Agent(
        client: $client,
        tools: (new ToolRegistry())->add(new FinishTool()),
        systemPrompt: 'тест',
        maxRounds: 3,
        finishTool: '',
    );

    assertSame('обычный ответ', $agent->ask('сделай'));
});

// ---------------------------------------------------------------------------
// Файлы
// ---------------------------------------------------------------------------

test('read_file: читает файл внутри корня', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/note.txt', "первая\nвторая\nтретья");

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => 'note.txt']);

    assertContains('строки 1–3 из 3', $result);
    assertContains('вторая', $result);
});

test('read_file: offset и limit в строках', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/note.txt', "1\n2\n3\n4\n5");

    $result = (new ReadFileTool(new FileSandbox($dir)))
        ->handle(['path' => 'note.txt', 'offset' => 1, 'limit' => 2]);

    assertContains('строки 2–3 из 5', $result);
    assertContains("2\n3", $result);
});

test('read_file: побег через .. запрещён', function (): void {
    $dir = testDir();
    file_put_contents(dirname($dir) . '/outside.txt', 'секрет');

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => '../outside.txt']);

    assertContains('выходит за пределы', $result);
});

test('read_file: абсолютный путь запрещён', function (): void {
    $dir = testDir();

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => '/etc/passwd']);

    assertContains('Абсолютные пути запрещены', $result);
});

test('read_file: бинарный файл не читается', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/bin.dat', "\x00\x01\x02\xFF");

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => 'bin.dat']);

    assertContains('бинарный', $result);
});

test('list_dir: показывает каталоги и файлы', function (): void {
    $dir = testDir();
    mkdir($dir . '/sub');
    file_put_contents($dir . '/a.txt', 'hello');

    $result = (new ListDirTool(new FileSandbox($dir)))->handle([]);

    assertContains('sub/', $result);
    assertContains('a.txt', $result);
});

test('list_dir: выход за корень запрещён', function (): void {
    $dir = testDir();

    $result = (new ListDirTool(new FileSandbox($dir)))->handle(['path' => '..']);

    assertContains('выходит за пределы', $result);
});

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

test('csv_query: фильтр по колонке', function (): void {
    $dir = testDir();
    file_put_contents(
        $dir . '/people.csv',
        "name,city,age\nИван,Тула,30\nМария,Москва,25\nПётр,Тула,41\n"
    );

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle([
        'path' => 'people.csv',
        'where' => 'city=Тула',
    ]);

    assertContains('Иван', $result);
    assertContains('Пётр', $result);
    assertTrue(!str_contains($result, 'Мария'), 'строка из другого города попала в выборку');
    assertContains('2 из 3 строк', $result);
});

test('csv_query: вхождение подстроки и лимит', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/people.csv', "name,city\nИван,Тула\nИгорь,Тула\nПётр,Тула\n");

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle([
        'path' => 'people.csv',
        'where' => 'name~и',
        'columns' => 'name',
        'limit' => 2,
    ]);

    assertContains('2 из 3 строк', $result);
    assertTrue(!str_contains($result, 'city'), 'лишняя колонка в выборке');
});

test('csv_query: неизвестная колонка — понятная ошибка', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/people.csv', "name,city\nИван,Тула\n");

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle([
        'path' => 'people.csv',
        'where' => 'город=Тула',
    ]);

    assertContains('Нет колонки', $result);
    assertContains('name, city', $result);
});

// ---------------------------------------------------------------------------
// Единицы и даты
// ---------------------------------------------------------------------------

test('convert_units: километры в мили', function (): void {
    assertSame('100 km = 62.137119 mi', (new ConvertUnitsTool())->handle([
        'value' => 100,
        'from' => 'km',
        'to' => 'mi',
    ]));
});

test('convert_units: температура', function (): void {
    $tool = new ConvertUnitsTool();

    assertSame('0 c = 32 f', $tool->handle(['value' => 0, 'from' => 'c', 'to' => 'f']));
    assertSame('100 c = 373.15 k', $tool->handle(['value' => 100, 'from' => 'c', 'to' => 'k']));
});

test('convert_units: русские названия единиц', function (): void {
    // Ответ приходит в канонических кодах, независимо от ввода.
    assertSame('5 km = 5000 m', (new ConvertUnitsTool())->handle([
        'value' => 5,
        'from' => 'км',
        'to' => 'м',
    ]));
});

test('convert_units: неизвестная единица', function (): void {
    $result = (new ConvertUnitsTool())->handle(['value' => 1, 'from' => 'parsec', 'to' => 'm']);

    assertContains('Не знаю единицы', $result);
});

test('date_calc: разница между датами', function (): void {
    $result = (new DateCalcTool())->handle(['from' => '2026-01-01', 'to' => '2026-02-01']);

    assertContains('31 день', $result);
});

test('date_calc: дата без времени — это полночь, а не «сейчас»', function (): void {
    $result = (new DateCalcTool())->handle(['from' => '2026-01-01', 'modifier' => '+1 day']);

    assertContains('2026-01-01 00:00:00', $result);
    assertContains('2026-01-02 00:00:00', $result);
});

test('date_calc: прибавить интервал', function (): void {
    $result = (new DateCalcTool())->handle(['from' => '2026-01-01', 'modifier' => '+30 days']);

    assertContains('2026-01-31', $result);
});

test('date_calc: без параметров — подсказка', function (): void {
    assertContains('Нужен либо to', (new DateCalcTool())->handle([]));
});

test('date_calc: плохой часовой пояс', function (): void {
    assertContains('Неизвестный часовой пояс', (new DateCalcTool())->handle([
        'from' => 'now',
        'timezone' => 'Mars/Olympus',
    ]));
});

// ---------------------------------------------------------------------------
// UUID и хеши
// ---------------------------------------------------------------------------

test('uuid: формат v4 и уникальность', function (): void {
    $tool = new UuidTool();
    $first = $tool->handle([]);
    $second = $tool->handle([]);

    assertTrue(
        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first) === 1,
        'не UUID v4: ' . $first
    );
    assertTrue($first !== $second, 'два вызова вернули одинаковый UUID');
});

test('hash_text: известные значения', function (): void {
    $tool = new HashTool();

    assertSame('sha256: ' . hash('sha256', 'привет'), $tool->handle(['text' => 'привет']));
    assertSame('md5: ' . md5('x'), $tool->handle(['text' => 'x', 'algo' => 'md5']));
    assertContains('Неизвестный алгоритм', $tool->handle(['text' => 'x', 'algo' => 'crc32']));
});

// ---------------------------------------------------------------------------
// http_request: сборка запроса (без сети)
// ---------------------------------------------------------------------------

test('http_request: query добавляется к URL', function (): void {
    $request = HttpRequest::build('get', 'https://example.com/search', ['q' => 'кот', 'page' => 2]);

    assertSame('GET', $request->method);
    assertContains('q=%D0%BA%D0%BE%D1%82', $request->url);
    assertContains('page=2', $request->url);
});

test('http_request: JSON-тело и Content-Type', function (): void {
    $request = HttpRequest::build('POST', 'https://example.com/orders', body: ['sku' => 'A-1']);

    assertSame('{"sku":"A-1"}', $request->body);
    assertSame('application/json', $request->headers['Content-Type']);
    assertSame('POST', $request->method);
});

test('http_request: form-тело', function (): void {
    $request = HttpRequest::build(
        'POST',
        'https://example.com/login',
        body: ['u' => 'a', 'p' => 'b'],
        contentType: 'form',
    );

    assertSame('u=a&p=b', $request->body);
    assertSame('application/x-www-form-urlencoded', $request->headers['Content-Type']);
});

test('http_request: Basic-авторизация', function (): void {
    $request = HttpRequest::build(
        'GET',
        'https://example.com/me',
        auth: ['user' => 'bot', 'pass' => 'secret'],
    );

    assertSame('Basic ' . base64_encode('bot:secret'), $request->headers['Authorization']);
});

test('http_request: Bearer-авторизация', function (): void {
    $request = HttpRequest::build(
        'GET',
        'https://example.com/me',
        auth: ['type' => 'bearer', 'token' => 'abc'],
    );

    assertSame('Bearer abc', $request->headers['Authorization']);
});

test('http_request: запрещённые заголовки отбрасываются', function (): void {
    $request = HttpRequest::build(
        'GET',
        'https://example.com',
        headers: ['Host' => 'evil', 'X-Ok' => '1'],
    );

    assertTrue(!isset($request->headers['Host']), 'Host должен отбрасываться');
    assertSame('1', $request->headers['X-Ok']);
});

test('http_request: неизвестный метод отвергается', function (): void {
    assertThrows(static fn () => HttpRequest::build('TRACE', 'https://example.com'));
});

test('http_request: без схемы подставляется https', function (): void {
    assertSame('https://example.com/x', HttpRequest::build('GET', 'example.com/x')->url);
});

test('http: заголовки превращаются в строки cURL', function (): void {
    // Регрессия: ассоциативный массив, отданный в cURL напрямую,
    // молча терял и Content-Type, и Authorization.
    assertSame(['X-Ok: 1'], Http::headerLines(['X-Ok' => '1']));
    assertSame(['Accept: */*'], Http::headerLines([]));
    assertSame(['Accept: */*'], Http::headerLines(['Accept: */*']));
});

// ---------------------------------------------------------------------------
// http_request: защита от SSRF (без сети)
// ---------------------------------------------------------------------------

test('http_request: localhost запрещён', function (): void {
    assertContains('локальному адресу', (new HttpRequestTool())->handle(['url' => 'http://localhost/x']));
});

test('http_request: приватные адреса запрещены', function (): void {
    $tool = new HttpRequestTool();

    assertContains('приватному адресу', $tool->handle(['url' => 'http://127.0.0.1/x']));
    assertContains('приватному адресу', $tool->handle(['url' => 'http://10.0.0.5/x']));
    assertContains('приватному адресу', $tool->handle(['url' => 'http://192.168.1.10/x']));
});

test('http_request: только http и https', function (): void {
    assertContains('только http и https', (new HttpRequestTool())->handle(['url' => 'ftp://example.com/x']));
});

test('http_request: allowPrivate снимает блокировку внутренней сети', function (): void {
    // Порт закрыт: проверяем именно то, что SSRF-отказ больше не выдаётся.
    $result = (new HttpRequestTool(allowPrivate: true))->handle(['url' => 'http://127.0.0.1:9/x']);

    assertTrue(
        !str_contains($result, 'приватному адресу'),
        'с allowPrivate адрес не должен блокироваться: ' . $result
    );
});

// ---------------------------------------------------------------------------

removeDir(__DIR__ . '/tmp');

echo PHP_EOL . 'пройдено: ' . $passed . ', провалено: ' . $failed . PHP_EOL;

exit($failed === 0 ? 0 : 1);
