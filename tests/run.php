<?php

declare(strict_types=1);

/**
 * Раннер тестов: подключает кейсы из tests/cases и печатает итог.
 *
 * Запуск:
 *   php tests/run.php
 */

require __DIR__ . '/support.php';

$cases = glob(__DIR__ . '/cases/*.php') ?: [];
sort($cases);

foreach ($cases as $case) {
    require $case;
}

removeDir(__DIR__ . '/tmp');

echo PHP_EOL . 'пройдено: ' . $passed . ', провалено: ' . $failed . PHP_EOL;

exit($failed === 0 ? 0 : 1);
