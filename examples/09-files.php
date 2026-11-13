<?php

declare(strict_types=1);

/**
 * Пример 9. Файлы: чтение, запись, правка и поиск.
 *
 * Инструменты: read_file, list_dir, write_file, replace_in_file,
 *              search_files, find_files.
 * Песочница: ограниченная, корень — examples/var/sandbox.
 * Флаги: create_dirs (write_file), all (replace_in_file),
 *        path/glob/limit (поиск), FileSandbox::unrestricted() (см. блок 3).
 *
 * Запуск:
 *   php examples/09-files.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\FileSandbox;
use Rsgrinko\AiSdk\Tools\FindFilesTool;
use Rsgrinko\AiSdk\Tools\ListDirTool;
use Rsgrinko\AiSdk\Tools\ReadFileTool;
use Rsgrinko\AiSdk\Tools\ReplaceInFileTool;
use Rsgrinko\AiSdk\Tools\SearchFilesTool;
use Rsgrinko\AiSdk\Tools\WriteFileTool;

$root = __DIR__ . '/var/sandbox';
@mkdir($root . '/src', 0777, true);

// Содержимое пересоздаётся при каждом запуске — пример идемпотентен.
file_put_contents($root . '/src/app.php', "<?php\n\n// TODO: убрать это\nfunction run(): void {}\n");
file_put_contents($root . '/src/util.php', "<?php\n\nfunction ping(): string { return 'pong'; }\n");
file_put_contents($root . '/README.md', "# Песочница для примера\n");

$sandbox = new FileSandbox($root);

$tools = (new ToolRegistry())->add(
    new ReadFileTool($sandbox),
    new ListDirTool($sandbox),
    new WriteFileTool($sandbox),
    new ReplaceInFileTool($sandbox),
    new SearchFilesTool($sandbox),
    new FindFilesTool($sandbox),
);

$agent = new Agent(
    client: AiClient::fromEnv(),
    tools: $tools,
    systemPrompt: 'Отвечай кратко. Работай только с путями внутри проекта. '
        . 'Время, расчёты и другие факты узнавай только вызовом инструмента, из головы их не бери. '
        . 'Когда инструменты вернут результат, напиши по нему итоговый ответ текстом.',
    maxRounds: 5,
);

echo "1) поиск и правка\n";
echo $agent->ask('Найди все TODO в проекте и замени текст "убрать это" на "сделать это".') . PHP_EOL;

foreach ($agent->trace() as $step) {
    echo '   ' . $step['name'] . ' → '
        . mb_substr(str_replace("\n", ' | ', $step['output']), 0, 120) . PHP_EOL;
}

echo PHP_EOL . "2) что стало с файлом\n";
echo (string) file_get_contents($root . '/src/app.php') . PHP_EOL;

echo "3) режимы песочницы\n";
$restricted = new FileSandbox($root);
$free = FileSandbox::unrestricted($root);

echo '   ограниченная: ' . ($restricted->isRestricted() ? 'да' : 'нет') . PHP_EOL;
echo '   свободная:    ' . ($free->isRestricted() ? 'да' : 'нет') . PHP_EOL;
echo '   выход за корень: '
    . (new ReadFileTool($restricted))->handle(['path' => '../secret.txt']) . PHP_EOL;

// Каждый инструмент получает свой экземпляр песочницы, поэтому политика
// задаётся отдельно: например, читать везде, а писать только в проект.
// new ReadFileTool(FileSandbox::unrestricted());
