<?php

declare(strict_types=1);

/**
 * Точка входа SDK: автозагрузка классов + чтение .env.
 *
 * Во всех примерах и тестах достаточно одной строки:
 *   require __DIR__ . '/bootstrap.php';
 */

// Автозагрузка: Rsgrinko\AiSdk\Tools\* -> tools/, остальное Rsgrinko\AiSdk\* -> src/.
spl_autoload_register(static function (string $class): void {
    $map = [
        'Rsgrinko\\AiSdk\\Tools\\' => __DIR__ . '/tools/',
        'Rsgrinko\\AiSdk\\' => __DIR__ . '/src/',
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
\Rsgrinko\AiSdk\Env::load(__DIR__ . '/.env');
