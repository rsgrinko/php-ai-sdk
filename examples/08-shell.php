<?php

declare(strict_types=1);

/**
 * Пример 8. Выполнение команд оболочки.
 *
 * ВНИМАНИЕ: инструмент даёт модели запускать произвольные команды от имени
 * процесса. Регистрируйте его только там, где это действительно нужно,
 * и по возможности ограничивайте allowlist и рабочим каталогом.
 *
 * Инструменты: run_command.
 * Флаги: workdir, shell, timeout, maxOutput, allow, tmpDir.
 *
 * Запуск:
 *   php examples/08-shell.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Tools\ShellTool;

// Рабочий каталог фиксируем: команда не «гуляет» по всей машине случайно.
// tmpDir задаём явно: системный temp бывает недоступен в контейнерах.
$tmpDir = dirname(__DIR__) . '/var';
$shell = new ShellTool(workdir: dirname(__DIR__), timeout: 5.0, tmpDir: $tmpDir);

echo $shell->handle(['command' => 'php -v']) . PHP_EOL . PHP_EOL;
echo $shell->handle(['command' => 'echo shell works']) . PHP_EOL . PHP_EOL;

// Ограничение: разрешена только команда php.
$limited = new ShellTool(allow: ['php'], timeout: 3.0, tmpDir: $tmpDir);

echo $limited->handle(['command' => 'echo это запрещено']) . PHP_EOL;

