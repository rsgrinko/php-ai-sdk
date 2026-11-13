<?php

declare(strict_types=1);

// Тесты: AiClient по настоящему HTTP против поддельного сервера
// (tests/stub/llm-server.php). Внешняя сеть не нужна.

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\AiException;
use Rsgrinko\AiSdk\Retry;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;

/**
 * Поднять встроенный сервер PHP один раз на весь прогон.
 * stubServer(stop: true) — остановить.
 *
 * @return array{url: string, state: string}|null null — сервер не поднялся
 */
function stubServer(bool $stop = false): ?array
{
    static $server = false;
    static $process = null;

    if ($stop) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        $process = null;

        return $server = null;
    }

    if ($server !== false) {
        return $server;
    }

    $server = null;

    // Прокси из окружения не должен перехватывать запросы к 127.0.0.1.
    $noProxy = (string) getenv('NO_PROXY');

    if (!str_contains($noProxy, '127.0.0.1')) {
        putenv('NO_PROXY=' . ltrim($noProxy . ',127.0.0.1', ','));
    }

    $state = dirname(__DIR__) . '/tmp/stub-' . bin2hex(random_bytes(3));
    @mkdir($state, 0777, true);

    // Свободный порт: занимаем 0 и смотрим, что выдала ОС.
    $probe = @stream_socket_server('tcp://127.0.0.1:0');

    if ($probe === false) {
        return null;
    }

    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $process = proc_open(
        [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/stub/llm-server.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $state . '/server.log', 'w'], 2 => ['file', $state . '/server.log', 'a']],
        $pipes,
        null,
        ['STUB_STATE_DIR' => $state] + getenv(),
    );

    if (!is_resource($process)) {
        return null;
    }

    // Ждём, пока сервер начнёт принимать соединения.
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($socket !== false) {
            fclose($socket);

            return $server = ['url' => 'http://127.0.0.1:' . $port, 'state' => $state];
        }

        usleep(50_000);
    }

    return null;
}

/**
 * Клиент на сценарий поддельного сервера. Паузы повторов — миллисекунды.
 */
function stubClient(string $scenario, int $retries = 2): AiClient
{
    $server = stubServer();

    if ($server === null) {
        throw new RuntimeException('не удалось запустить встроенный сервер PHP');
    }

    return new AiClient(
        apiKey: 'test-key',
        model: 'stub',
        baseUrl: $server['url'] . '/' . $scenario,
        timeout: 5.0,
        retry: new Retry(maxRetries: $retries, baseDelay: 0.01),
    );
}

function stubCalls(string $scenario): int
{
    return (int) @file_get_contents(stubServer()['state'] . '/' . $scenario . '.count');
}

test('client: текстовый ответ и usage', function (): void {
    $result = stubClient('ok')->chat([['role' => 'user', 'content' => 'привет']]);

    assertSame('привет', $result->text);
    assertSame(false, $result->hasToolCalls());
    assertSame(11, $result->usage['prompt_tokens']);
});

test('client: tool_calls разбираются, аргументы из JSON-строки', function (): void {
    $result = stubClient('tools')->chat([['role' => 'user', 'content' => '2+2?']]);

    assertSame(true, $result->hasToolCalls());
    assertSame('call_42', $result->toolCalls[0]['id']);
    assertSame('calculator', $result->toolCalls[0]['name']);
    assertSame(['expression' => '2+2'], $result->toolCalls[0]['arguments']);
    assertSame('call_42', $result->assistantMessage['tool_calls'][0]['id']);
});

test('client: в запросе модель, ключ и инструменты', function (): void {
    $tools = (new ToolRegistry())->add(new CalculatorTool());
    $result = stubClient('echo')->chat([['role' => 'user', 'content' => 'x']], $tools->definitions(), [
        'tool_choice' => 'calculator',
        'parallel_tool_calls' => false,
    ]);

    $sent = json_decode($result->text, true);

    assertSame('stub', $sent['model']);
    assertSame('calculator', $sent['tools'][0]['function']['name']);
    assertSame(['type' => 'function', 'function' => ['name' => 'calculator']], $sent['tool_choice']);
    assertSame(false, $sent['parallel_tool_calls']);
});

test('client: 503 повторяется и запрос проходит со второго раза', function (): void {
    $result = stubClient('flaky')->chat([['role' => 'user', 'content' => 'x']]);

    assertSame('со второго раза', $result->text);
    assertSame(2, stubCalls('flaky'));
});

test('client: повторы кончились — понятная ошибка с HTTP-кодом', function (): void {
    $client = stubClient('down', retries: 2);
    $message = '';

    try {
        $client->chat([['role' => 'user', 'content' => 'x']]);
    } catch (AiException $e) {
        $message = $e->getMessage();
    }

    assertContains('HTTP 503', $message);
    assertSame(3, stubCalls('down'), 'первая попытка + 2 повтора');
});

test('client: 401 не повторяется', function (): void {
    $client = stubClient('bad-key');
    $message = '';

    try {
        $client->chat([['role' => 'user', 'content' => 'x']]);
    } catch (AiException $e) {
        $message = $e->getMessage();
    }

    assertContains('HTTP 401: Invalid API key', $message);
    assertSame(1, stubCalls('bad-key'));
});

test('client: не-JSON ответ — понятная ошибка', function (): void {
    $client = stubClient('garbage');

    assertThrows(static fn () => $client->chat([['role' => 'user', 'content' => 'x']]));
});

test('client: агент целиком по HTTP — инструмент, затем ответ', function (): void {
    // Сервер всегда отвечает tool_calls: агент упрётся в maxRounds и
    // попросит ответ без инструментов — это тоже проверка цикла по сети.
    $agent = new Agent(
        client: stubClient('tools'),
        tools: (new ToolRegistry())->add(new CalculatorTool()),
        maxRounds: 1,
    );

    $agent->ask('2+2?');

    assertSame('2+2 = 4', $agent->trace()[0]['output']);
    assertTrue($agent->usage()['prompt_tokens'] >= 22, 'usage копится за все запросы');
});

// ---------------------------------------------------------------------------
// Настройки: fromArray / create / fromEnv
// ---------------------------------------------------------------------------

/**
 * Выполнить $fn с заданными переменными окружения и вернуть их как было.
 *
 * @param array<string, string|null> $vars null — переменной нет
 */
function withEnv(array $vars, callable $fn): mixed
{
    $saved = [];

    foreach ($vars as $name => $value) {
        $saved[$name] = getenv($name);
        putenv($value === null ? $name : $name . '=' . $value);
    }

    try {
        return $fn();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }
}

/**
 * Тело запроса, которое клиент отправил бы модели.
 *
 * @return array<string, mixed>
 */
function sentBody(AiClient $client): array
{
    return (array) json_decode($client->chat([['role' => 'user', 'content' => 'x']])->text, true);
}

test('config: fromArray — настройки из массива, строки и типы', function (): void {
    $url = stubServer()['url'] . '/echo';

    $typed = sentBody(AiClient::fromArray([
        'base_url' => $url, 'model' => 'm1', 'temperature' => 0.1, 'max_tokens' => 77, 'disable_thinking' => true,
    ]));
    $strings = sentBody(AiClient::fromArray([
        'base_url' => $url, 'model' => 'm2', 'temperature' => '0.3', 'max_tokens' => '55', 'disable_thinking' => 'false',
    ]));

    assertSame(['m1', 0.1, 77, ['type' => 'disabled']], [$typed['model'], $typed['temperature'], $typed['max_tokens'], $typed['thinking']]);
    assertSame(['m2', 0.3, 55, false], [$strings['model'], $strings['temperature'], $strings['max_tokens'], isset($strings['thinking'])]);
});

test('config: fromArray не читает окружение', function (): void {
    $url = stubServer()['url'] . '/echo';

    $sent = withEnv(['AI_MODEL' => 'from-env', 'AI_MAX_TOKENS' => '9'], static fn () => sentBody(
        AiClient::fromArray(['base_url' => $url])
    ));

    assertSame('deepseek-chat', $sent['model']);
    assertSame(1000, $sent['max_tokens']);
});

test('config: create — массив важнее окружения, недостающее из окружения', function (): void {
    $url = stubServer()['url'] . '/echo';

    $sent = withEnv(['AI_MODEL' => 'from-env', 'AI_MAX_TOKENS' => '9', 'AI_TEMPERATURE' => null], static fn () => sentBody(
        AiClient::create(['base_url' => $url, 'model' => 'from-array'])
    ));

    assertSame('from-array', $sent['model']);
    assertSame(9, $sent['max_tokens']);
    assertSame(0.7, $sent['temperature']);
});

test('config: fromEnv — как раньше, из переменных AI_*', function (): void {
    $url = stubServer()['url'] . '/echo';

    $sent = withEnv(['AI_BASE_URL' => $url, 'AI_MODEL' => 'env-model', 'AI_MAX_RETRIES' => '0'], static fn () => sentBody(
        AiClient::fromEnv()
    ));

    assertSame('env-model', $sent['model']);
});

test('config: неизвестный ключ — исключение со списком доступных', function (): void {
    foreach ([
        static fn () => AiClient::fromArray(['modle' => 'x']),
        static fn () => AiClient::create(['api-key' => 'x']),
        static fn () => Agent::fromArray(['max_round' => 3]),
    ] as $build) {
        try {
            $build();
            assertTrue(false, 'ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            assertContains('Неизвестная настройка', $e->getMessage());
            assertContains('max_rounds', $e->getMessage());
        }
    }
});

test('config: Agent::fromArray — один массив для клиента и агента', function (): void {
    $agent = Agent::fromArray(
        ['base_url' => stubServer()['url'] . '/echo', 'model' => 'shared', 'tool_choice' => 'calculator', 'max_retries' => 0],
        (new ToolRegistry())->add(new CalculatorTool()),
    );

    $sent = json_decode($agent->ask('x'), true);

    assertSame('shared', $sent['model']);
    assertSame(['type' => 'function', 'function' => ['name' => 'calculator']], $sent['tool_choice']);
});

test('config: bool понимает true/yes/on/1 и пустое как значение по умолчанию', function (): void {
    $config = Rsgrinko\AiSdk\Config::fromArray([
        'verify_ssl' => 'yes', 'disable_thinking' => '0', 'parallel_tool_calls' => '',
    ]);

    assertSame(true, $config->bool('verify_ssl', false));
    assertSame(false, $config->bool('disable_thinking', true));
    assertSame(true, $config->bool('parallel_tool_calls', true));
    assertSame(false, $config->bool('max_rounds', false));
});

// Остановить сервер до того, как run.php удалит tests/tmp
// (на Windows открытый лог не даёт удалить каталог).
stubServer(stop: true);
