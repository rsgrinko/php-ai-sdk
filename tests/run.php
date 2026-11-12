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
use AiSdk\Tool;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\TimeTool;

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

echo PHP_EOL . 'пройдено: ' . $passed . ', провалено: ' . $failed . PHP_EOL;

exit($failed === 0 ? 0 : 1);
