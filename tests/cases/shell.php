<?php

declare(strict_types=1);

// Тесты: shell. Запускаются из tests/run.php.

use AiSdk\Tools\ShellTool;

// ---------------------------------------------------------------------------
// run_command: оболочка
// ---------------------------------------------------------------------------

test('shell: выполняет команду и возвращает код', function (): void {
    $result = (new ShellTool(tmpDir: __DIR__ . '/tmp'))->handle(['command' => 'echo hello']);

    assertContains('hello', $result);
    assertContains('exit: 0', $result);
});

test('shell: возвращает ненулевой код выхода', function (): void {
    assertContains('exit: 3', (new ShellTool(tmpDir: __DIR__ . '/tmp'))->handle(['command' => 'exit 3']));
});

test('shell: таймаут прерывает команду', function (): void {
    $command = DIRECTORY_SEPARATOR === '\\' ? 'ping -n 5 127.0.0.1 >NUL' : 'sleep 5';

    $started = microtime(true);
    $result = (new ShellTool(timeout: 0.5, tmpDir: __DIR__ . '/tmp'))->handle(['command' => $command]);
    $elapsed = microtime(true) - $started;

    assertContains('таймаут', $result);
    assertTrue($elapsed < 3.0, 'таймаут не сработал: ' . round($elapsed, 2) . ' с');
});

test('shell: allowlist запрещает лишнее', function (): void {
    $result = (new ShellTool(allow: ['echo'], tmpDir: __DIR__ . '/tmp'))->handle(['command' => 'whoami']);

    assertContains('не разрешена', $result);
});

test('shell: allowlist пропускает разрешённое', function (): void {
    $result = (new ShellTool(allow: ['echo'], tmpDir: __DIR__ . '/tmp'))->handle(['command' => 'echo ok']);

    assertContains('ok', $result);
});

test('shell: длинный вывод обрезается', function (): void {
    $result = (new ShellTool(maxOutput: 100, tmpDir: __DIR__ . '/tmp'))->handle(['command' => 'echo ' . str_repeat('x', 500)]);

    assertContains('обрезано', $result);
});

test('shell: пустая команда не запускается', function (): void {
    assertContains('Пустая команда', (new ShellTool(tmpDir: __DIR__ . '/tmp'))->handle(['command' => '   ']));
});


