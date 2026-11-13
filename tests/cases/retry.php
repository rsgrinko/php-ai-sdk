<?php

declare(strict_types=1);

// Тесты: повтор запроса к модели при временных сбоях. Без сети и без пауз.

use AiSdk\AiException;
use AiSdk\Retry;

/**
 * Retry с записью пауз вместо реального ожидания.
 *
 * @param list<float> $sleeps
 */
function recordingRetry(array &$sleeps, int $maxRetries = 2, float $maxDelay = 30.0): Retry
{
    return new Retry(
        maxRetries: $maxRetries,
        baseDelay: 1.0,
        maxDelay: $maxDelay,
        sleep: static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    );
}

/**
 * Попытка, которая по очереди отдаёт заготовленные ответы или бросает исключения.
 *
 * @param list<array<string, mixed>|\Throwable> $script
 */
function scriptedAttempt(array $script, int &$calls): \Closure
{
    return static function () use (&$script, &$calls): array {
        $calls++;
        $next = array_shift($script);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next + ['body' => '', 'retry_after' => null];
    };
}

test('retry: 429 и 503 повторяются, потом успех', function (): void {
    $sleeps = [];
    $calls = 0;

    $response = recordingRetry($sleeps)->run(scriptedAttempt([
        ['status' => 429],
        ['status' => 503],
        ['status' => 200],
    ], $calls));

    assertSame(200, $response['status']);
    assertSame(3, $calls);
    assertSame(2, count($sleeps));
    assertTrue($sleeps[0] >= 0.5 && $sleeps[0] <= 1.0, 'первая пауза 0.5–1 с: ' . $sleeps[0]);
    assertTrue($sleeps[1] >= 1.0 && $sleeps[1] <= 2.0, 'вторая пауза 1–2 с: ' . $sleeps[1]);
});

test('retry: повторы кончились — возвращается последний ответ с ошибкой', function (): void {
    $sleeps = [];
    $calls = 0;

    $response = recordingRetry($sleeps, maxRetries: 1)->run(scriptedAttempt([
        ['status' => 500],
        ['status' => 502],
        ['status' => 200],
    ], $calls));

    assertSame(502, $response['status']);
    assertSame(2, $calls);
});

test('retry: 400, 401 и 404 не повторяются', function (): void {
    foreach ([400, 401, 404] as $status) {
        $sleeps = [];
        $calls = 0;

        $response = recordingRetry($sleeps)->run(scriptedAttempt([['status' => $status], ['status' => 200]], $calls));

        assertSame($status, $response['status']);
        assertSame(1, $calls, 'HTTP ' . $status);
    }
});

test('retry: Retry-After важнее расчёта', function (): void {
    $sleeps = [];
    $calls = 0;

    recordingRetry($sleeps)->run(scriptedAttempt([
        ['status' => 429, 'retry_after' => '7'],
        ['status' => 200],
    ], $calls));

    assertSame([7.0], $sleeps);
});

test('retry: Retry-After больше maxDelay — не ждём, отдаём ошибку', function (): void {
    $sleeps = [];
    $calls = 0;

    $response = recordingRetry($sleeps, maxDelay: 10.0)->run(scriptedAttempt([
        ['status' => 429, 'retry_after' => '120'],
        ['status' => 200],
    ], $calls));

    assertSame(429, $response['status']);
    assertSame(1, $calls);
    assertSame([], $sleeps);
});

test('retry: Retry-After в виде HTTP-даты', function (): void {
    $delay = (new Retry())->delay(0, gmdate('D, d M Y H:i:s', time() + 5) . ' GMT');

    assertTrue($delay !== null && $delay >= 3.0 && $delay <= 5.0, 'пауза ~5 с: ' . var_export($delay, true));
});

test('retry: обрыв связи повторяется', function (): void {
    $sleeps = [];
    $calls = 0;

    $response = recordingRetry($sleeps)->run(scriptedAttempt([
        new AiException('HTTP-ошибка: Connection reset', 56),
        ['status' => 200],
    ], $calls));

    assertSame(200, $response['status']);
    assertSame(2, $calls);
});

test('retry: таймаут не повторяется', function (): void {
    $sleeps = [];
    $calls = 0;
    $retry = recordingRetry($sleeps);
    $attempt = scriptedAttempt([
        new AiException('HTTP-ошибка: Operation timed out', 28),
        ['status' => 200],
    ], $calls);

    assertThrows(static fn () => $retry->run($attempt));

    assertSame(1, $calls);
});

test('retry: сетевая ошибка после всех повторов пробрасывается', function (): void {
    $sleeps = [];
    $calls = 0;
    $retry = recordingRetry($sleeps, maxRetries: 2);
    $attempt = scriptedAttempt([
        new AiException('reset', 56),
        new AiException('reset', 56),
        new AiException('reset', 56),
    ], $calls);

    assertThrows(static fn () => $retry->run($attempt));

    assertSame(3, $calls);
});

test('retry: Retry::none() — одна попытка', function (): void {
    $calls = 0;

    $response = Retry::none()->run(scriptedAttempt([['status' => 503], ['status' => 200]], $calls));

    assertSame(503, $response['status']);
    assertSame(1, $calls);
});

test('retry: пауза не превышает maxDelay', function (): void {
    assertTrue((new Retry(baseDelay: 10.0, maxDelay: 15.0))->delay(5, null) <= 15.0);
});
