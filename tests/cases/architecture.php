<?php

declare(strict_types=1);

// Тесты: архитектурные границы между слоями.

test('архитектура: ядро не зависит от инструментов', function (): void {
    foreach (glob(dirname(__DIR__, 2) . '/src/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);

        assertTrue(
            preg_match('/^use AiSdk\\\\Tools\\\\/m', $code) !== 1,
            'ядро импортирует инструменты: ' . basename($file)
        );
    }
});

test('архитектура: инструменты не создают клиента модели', function (): void {
    foreach (glob(dirname(__DIR__, 2) . '/tools/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);

        assertTrue(
            str_contains($code, 'AiClient') === false,
            'инструмент зависит от AiClient: ' . basename($file)
        );
    }
});

test('архитектура: инструменты не работают с cURL напрямую', function (): void {
    foreach (glob(dirname(__DIR__, 2) . '/tools/*.php') ?: [] as $file) {
        $code = (string) file_get_contents($file);

        assertTrue(
            str_contains($code, 'curl_') === false,
            'инструмент вызывает cURL напрямую: ' . basename($file)
        );
    }
});
