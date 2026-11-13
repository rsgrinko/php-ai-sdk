<?php

declare(strict_types=1);

/**
 * Пример 3. Свой инструмент за 4 метода.
 *
 * Класс ниже — шаблон: скопируйте, переименуйте и поменяйте name/description/
 * parameters/handle. Регистрация — одна строка через $tools->add(...).
 *
 * Инструменты: server_info — свой инструмент.
 * Флаги: нет; инструмент реализует контракт Tool.
 *
 * Запуск:
 *   php examples/03-custom-tool.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\Tool;
use Rsgrinko\AiSdk\ToolRegistry;

/**
 * Инструмент «сведения о сервере».
 * Параметр section — необязательный, поэтому модель может его не передавать.
 */
final class ServerInfoTool implements Tool
{
    public function name(): string
    {
        return 'server_info';
    }

    public function description(): string
    {
        return 'Сведения о машине, где работает скрипт: версия PHP, ОС, свободное место, лимит памяти.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'enum' => ['php', 'os', 'disk', 'all'],
                    'description' => 'Что именно вернуть. По умолчанию all.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $section = (string) ($args['section'] ?? 'all');
        $lines = [];

        if ($section === 'php' || $section === 'all') {
            $lines[] = 'PHP ' . PHP_VERSION;
        }

        if ($section === 'os' || $section === 'all') {
            $lines[] = 'ОС: ' . PHP_OS_FAMILY . ' (' . php_uname('m') . ')';
        }

        if ($section === 'disk' || $section === 'all') {
            $free = @disk_free_space(__DIR__);
            $lines[] = 'Свободно на диске: ' . ($free === false ? 'нет данных' : $this->human((float) $free));
        }

        if ($section === 'all') {
            $lines[] = 'Лимит памяти: ' . ini_get('memory_limit');
        }

        return $lines === [] ? 'Неизвестный раздел: ' . $section : implode(PHP_EOL, $lines);
    }

    private function human(float $bytes): string
    {
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1) . ' ' . $units[$index];
    }
}

$tools = new ToolRegistry();
$tools->add(new ServerInfoTool());

$agent = new Agent(AiClient::fromEnv(), $tools);

echo $agent->ask('На каком сервере я запускаюсь? Версия PHP и сколько свободного места на диске?') . PHP_EOL;

foreach ($agent->trace() as $step) {
    echo PHP_EOL . 'инструмент: ' . $step['name'] . ' ' . json_encode($step['arguments'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo $step['output'] . PHP_EOL;
}

