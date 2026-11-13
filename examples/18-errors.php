<?php

declare(strict_types=1);

/**
 * Пример 18. Ошибки и повторы: что делать, когда модель недоступна.
 *
 * Инструменты: нет.
 * Флаги: retry (Retry с логированием пауз), Retry::none().
 *
 * Любая ошибка обращения к модели — AiException. Временные сбои
 * (обрыв связи, 429, 5xx) клиент сам повторяет с растущей паузой.
 * Здесь сервер заведомо недоступен, поэтому видно все попытки.
 * Модель для примера не нужна.
 *
 * Запуск:
 *   php examples/18-errors.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\AiClient;
use AiSdk\AiException;
use AiSdk\Retry;

// На порту 9 локально никто не слушает: соединение отклоняется сразу.
// Если у вас задан HTTP_PROXY, добавьте 127.0.0.1 в NO_PROXY.
$deadUrl = 'http://127.0.0.1:9/v1';

echo '1) три повтора с растущей паузой' . PHP_EOL;

$retry = new Retry(
    maxRetries: 3,
    baseDelay: 0.2,
    // Свой sleep: печатаем паузу и ждём по-настоящему.
    sleep: static function (float $seconds): void {
        printf('   пауза %.2f с, повторяем…%s', $seconds, PHP_EOL);
        usleep((int) round($seconds * 1_000_000));
    },
);

$client = new AiClient(apiKey: '', model: 'any', baseUrl: $deadUrl, timeout: 5.0, retry: $retry);
$started = microtime(true);

try {
    $client->chat([['role' => 'user', 'content' => 'привет']]);
} catch (AiException $e) {
    printf('   AiException через %.1f с: %s%s', microtime(true) - $started, $e->getMessage(), PHP_EOL);
}

echo PHP_EOL . '2) Retry::none() — ошибка сразу' . PHP_EOL;

$agent = new Agent(new AiClient(apiKey: '', model: 'any', baseUrl: $deadUrl, retry: Retry::none()));

try {
    $agent->ask('привет');
} catch (AiException $e) {
    echo '   ' . $e->getMessage() . PHP_EOL;
    echo '   В приложении здесь показывают «модель недоступна, попробуйте позже».' . PHP_EOL;
}
