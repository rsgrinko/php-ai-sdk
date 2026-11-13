<?php

declare(strict_types=1);

/**
 * Поддельный OpenAI-совместимый сервер для тестов AiClient.
 *
 * Запускается встроенным сервером PHP:
 *   php -S 127.0.0.1:PORT tests/stub/llm-server.php
 *
 * Первый сегмент пути — сценарий:
 *   /ok/chat/completions      — обычный текстовый ответ
 *   /tools/chat/completions   — ответ с tool_calls
 *   /flaky/chat/completions   — первый запрос 503 (Retry-After: 0), потом ответ
 *   /down/chat/completions    — всегда 503
 *   /bad-key/chat/completions — 401 с ошибкой в JSON
 *   /garbage/chat/completions — 200, но не JSON
 *   /echo/chat/completions    — в ответе тело запроса (проверить, что ушло)
 *
 * Счётчик запросов по сценарию — в каталоге STUB_STATE_DIR: файл <сценарий>.count.
 */

$scenario = explode('/', trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0] ?? '';
$stateDir = (string) getenv('STUB_STATE_DIR');
$counter = $stateDir . '/' . preg_replace('/[^a-z-]/', '', $scenario) . '.count';
$calls = (int) @file_get_contents($counter) + 1;
file_put_contents($counter, (string) $calls);

header('Content-Type: application/json');

/**
 * @param array<string, mixed> $message
 */
function reply(array $message): void
{
    echo json_encode([
        'choices' => [['message' => $message + ['role' => 'assistant']]],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3],
    ], JSON_UNESCAPED_UNICODE);
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => ['message' => $message]]);
}

switch ($scenario) {
    case 'ok':
        reply(['content' => 'привет']);
        break;

    case 'tools':
        reply([
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_42',
                'type' => 'function',
                'function' => ['name' => 'calculator', 'arguments' => '{"expression":"2+2"}'],
            ]],
        ]);
        break;

    case 'flaky':
        if ($calls === 1) {
            header('Retry-After: 0');
            fail(503, 'перегружен');
            break;
        }

        reply(['content' => 'со второго раза']);
        break;

    case 'down':
        fail(503, 'перегружен');
        break;

    case 'bad-key':
        fail(401, 'Invalid API key');
        break;

    case 'garbage':
        header('Content-Type: text/html');
        echo '<html>502 Bad Gateway</html>';
        break;

    case 'echo':
        reply(['content' => (string) file_get_contents('php://input')]);
        break;

    default:
        fail(404, 'нет сценария ' . $scenario);
}
