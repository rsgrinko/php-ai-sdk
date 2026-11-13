<?php

declare(strict_types=1);

/**
 * Пример 17. Подтверждение опасных действий (toolApproval).
 *
 * Инструменты: list_dir, read_file, write_file (в песочнице).
 * Флаги: toolApproval — колбэк перед каждым вызовом.
 *
 * Колбэк получает имя инструмента и аргументы. Вернул false — инструмент
 * не выполняется, модель получает текст отказа и сама сообщает о нём.
 * В CLI здесь можно спросить пользователя (readline), в вебе — показать кнопку.
 *
 * Запуск:
 *   php examples/17-approval.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\FileSandbox;
use Rsgrinko\AiSdk\Tools\ListDirTool;
use Rsgrinko\AiSdk\Tools\ReadFileTool;
use Rsgrinko\AiSdk\Tools\WriteFileTool;

$root = __DIR__ . '/var/approval';
@mkdir($root, 0777, true);
file_put_contents($root . '/notes.txt', "купить молоко\n");
@unlink($root . '/todo.txt');

$sandbox = new FileSandbox($root);
$tools = (new ToolRegistry())->add(
    new ListDirTool($sandbox),
    new ReadFileTool($sandbox),
    new WriteFileTool($sandbox),
);

// Политика: читать можно всё, писать — нельзя.
$approval = static function (string $name, array $args): bool {
    $allowed = $name !== 'write_file';
    echo '  [' . ($allowed ? 'разрешено' : 'ЗАПРЕЩЕНО') . '] ' . $name . ' '
        . json_encode($args, JSON_UNESCAPED_UNICODE) . PHP_EOL;

    return $allowed;
};

$agent = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    tools: $tools,
    systemPrompt: 'Ты работаешь с файлами через инструменты. Отвечай кратко.',
    maxRounds: 5,
    toolApproval: $approval,
);

echo $agent->ask('Прочитай notes.txt и запиши его содержимое в todo.txt.') . PHP_EOL . PHP_EOL;

$blocked = array_filter($agent->trace(), static fn (array $step): bool => $step['blocked']);
echo 'заблокировано вызовов: ' . count($blocked) . PHP_EOL;
echo 'todo.txt создан: ' . (is_file($root . '/todo.txt') ? 'да (ошибка!)' : 'нет') . PHP_EOL;
