<?php

declare(strict_types=1);

/**
 * Пример 1. Простой вопрос к модели — без инструментов.
 *
 * Запуск:
 *   php examples/01-chat.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\AiClient;

$client = AiClient::fromEnv();

$result = $client->chat([
    ['role' => 'system', 'content' => 'Отвечай одним коротким предложением.'],
    ['role' => 'user', 'content' => 'Что такое MeshCore?'],
]);

echo $result->text . PHP_EOL;

if ($result->usage !== []) {
    echo PHP_EOL . 'токены: '
        . ($result->usage['prompt_tokens'] ?? 0) . ' → '
        . ($result->usage['completion_tokens'] ?? 0) . PHP_EOL;
}
