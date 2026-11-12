<?php

declare(strict_types=1);

// Тесты: tools. Запускаются из tests/run.php.

use AiSdk\Agent;
use AiSdk\ChatClient;
use AiSdk\ChatResult;
use AiSdk\HttpTransport;
use AiSdk\HttpRequest;
use AiSdk\LoggingClient;
use AiSdk\Tool;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\ConvertUnitsTool;
use AiSdk\Tools\CsvQueryTool;
use AiSdk\Tools\DateCalcTool;
use AiSdk\Tools\FileSandbox;
use AiSdk\Tools\FindFilesTool;
use AiSdk\Tools\JsonQueryTool;
use AiSdk\Tools\FinishTool;
use AiSdk\Tools\HashTool;
use AiSdk\Tools\HttpRequestTool;
use AiSdk\Tools\ListDirTool;
use AiSdk\Tools\ReadFileTool;
use AiSdk\Tools\ReplaceInFileTool;
use AiSdk\Tools\SearchFilesTool;
use AiSdk\Tools\ShellTool;
use AiSdk\Tools\TimeTool;
use AiSdk\Tools\WriteFileTool;
use AiSdk\Tools\UuidTool;

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
// JSON
// ---------------------------------------------------------------------------

test('json_query: вложенный путь', function (): void {
    $result = (new JsonQueryTool())->handle([
        'json' => '{"data":{"items":[{"id":42,"name":"x"}]}}',
        'path' => 'data.items[0].id',
    ]);

    assertContains('42', $result);
});

test('json_query: нет ключа — сообщение', function (): void {
    $result = (new JsonQueryTool())->handle(['json' => '{"a":1}', 'path' => 'b']);

    assertContains('не найден', $result);
});

test('json_query: битый JSON', function (): void {
    $result = (new JsonQueryTool())->handle(['json' => '{oops', 'path' => 'a']);

    assertContains('Некорректный JSON', $result);
});


