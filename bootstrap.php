<?php

declare(strict_types=1);

/**
 * Точка входа SDK: автозагрузка классов + чтение .env.
 *
 * Во всех примерах и тестах достаточно одной строки:
 *   require __DIR__ . '/bootstrap.php';
 */

// Автозагрузка: AiSdk\Tools\* -> tools/, остальное AiSdk\* -> src/.
spl_autoload_register(static function (string $class): void {
    $map = [
        'AiSdk\\Tools\\' => __DIR__ . '/tools/',
        'AiSdk\\' => __DIR__ . '/src/',
    ];

    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});

// .env рядом с bootstrap.php; если файла нет — работаем на значениях по умолчанию.
\AiSdk\Env::load(__DIR__ . '/.env');
