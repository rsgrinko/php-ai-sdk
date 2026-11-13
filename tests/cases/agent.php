<?php

declare(strict_types=1);

// Тесты: agent. Запускаются из tests/run.php.

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\Tool;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\FinishTool;

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


