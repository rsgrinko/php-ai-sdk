<?php

declare(strict_types=1);

/**
 * Пример 13. Задача «с нуля»: агент пишет код, пример проверяет и возвращает ошибку.
 *
 * Инструменты: read_file, write_file, replace_in_file, list_dir, run_command.
 * Флаги: workdir (песочница задачи), allow=['php'], maxRounds=6.
 *
 * Сценарий: в песочнице лежит тест и заглушка функции. Модель реализует
 * функцию, запускает тест и исправляет ошибки.
 *
 * Главное: проверка НЕ доверяется модели. Пример сам запускает тест, следит
 * за целостностью контрольного файла и, если тест упал, даёт новую попытку
 * с текстом ошибки. Это внешний цикл «сделал → проверил → поправил».
 *
 * Запуск:
 *   php examples/13-code-task.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\FileSandbox;
use Rsgrinko\AiSdk\Tools\ListDirTool;
use Rsgrinko\AiSdk\Tools\ReadFileTool;
use Rsgrinko\AiSdk\Tools\ReplaceInFileTool;
use Rsgrinko\AiSdk\Tools\ShellTool;
use Rsgrinko\AiSdk\Tools\WriteFileTool;

$root = __DIR__ . '/var/code-task';
$tmp = __DIR__ . '/var/tmp';
$solution = $root . '/src/slugify.php';
$testPath = $root . '/test.php';

// Песочница задачи создаётся заново: пример можно запускать повторно.
@mkdir($root . '/src', 0777, true);
@mkdir($tmp, 0777, true);

file_put_contents($solution, <<<'PHP'
<?php

declare(strict_types=1);

function slugify(string $text): string
{
    // TODO: реализовать
    return $text;
}
PHP);

file_put_contents($testPath, <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/src/slugify.php';

$cases = [
    ['Hello World', 'hello-world'],
    ['PHP SDK', 'php-sdk'],
];

foreach ($cases as [$input, $expected]) {
    $actual = slugify($input);

    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: '{$input}' -> '{$actual}', ожидалось '{$expected}'" . PHP_EOL);
        exit(1);
    }
}

echo 'OK' . PHP_EOL;
PHP);

// Контрольная копия теста: по ней откатываем, если модель его переписала.
file_put_contents($testPath . '.orig', (string) file_get_contents($testPath));
$testHash = (string) hash_file('sha256', $testPath);

$sandbox = new FileSandbox($root);

$tools = (new ToolRegistry())->add(
    new ReadFileTool($sandbox),
    new WriteFileTool($sandbox),
    new ReplaceInFileTool($sandbox),
    new ListDirTool($sandbox),
    new ShellTool(
        workdir: $root,
        timeout: 10.0,
        maxOutput: 4000,
        allow: ['php'],
        tmpDir: $tmp,
    ),
);

/**
 * Независимая проверка: гоняем тест процессом, а не через модель.
 *
 * @return array{0: int, 1: list<string>} код выхода и строки вывода
 */
function runTest(string $script): array
{
    $output = [];
    $code = 0;
    exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $code);

    return [$code, $output];
}

$attempts = 3;
$passed = false;
$lastError = '';
$output = [];

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    echo PHP_EOL . "===== попытка {$attempt} =====" . PHP_EOL;

    // Свежая история на каждую попытку: модель не тащит за собой прошлые ошибки.
    $agent = new Agent(
        client: AiClient::fromEnv(timeout: 120.0),
        tools: $tools,
        systemPrompt: 'Ты программист PHP. Сначала прочитай тест, потом реализуй функцию. '
            . 'Меняй только src/slugify.php: test.php трогать нельзя. '
            . 'После правки запусти php test.php. '
            . 'Когда инструменты вернут результат, напиши по нему итоговый ответ текстом.',
        maxRounds: 6,
    );

    $prompt = $attempt === 1
        ? 'Сделай так, чтобы php test.php завершался успешно (exit 0). Меняй только src/slugify.php.'
        : "Тест всё ещё падает:\n" . $lastError
            . "\n\nТекущий src/slugify.php:\n" . (string) file_get_contents($solution)
            . "\nИсправь только этот файл и снова запусти тест. test.php менять нельзя.";

    echo $agent->ask($prompt) . PHP_EOL;

    echo '--- что делала модель ---' . PHP_EOL;

    foreach ($agent->trace() as $step) {
        echo '  ' . $step['name'] . ' → '
            . mb_substr(str_replace("\n", ' | ', $step['output']), 0, 110) . PHP_EOL;
    }

    // Проверяем и результат, и честность: контрольный файл не должен меняться.
    $testIntact = hash_file('sha256', $testPath) === $testHash;

    if (!$testIntact) {
        echo '! модель изменила test.php — откатываю контрольный файл' . PHP_EOL;
        file_put_contents($testPath, (string) file_get_contents($testPath . '.orig'));
    }

    [$code, $output] = runTest($testPath);

    echo 'проверка: ' . ($code === 0 && $testIntact ? 'PASS' : 'FAIL') . PHP_EOL;

    if ($code === 0 && $testIntact) {
        $passed = true;
        break;
    }

    $lastError = implode("\n", array_slice($output, -3));
}

echo PHP_EOL . '===== итог =====' . PHP_EOL;
echo ($passed ? 'PASS' : 'FAIL') . ': ' . implode(' | ', array_slice($output, -3)) . PHP_EOL;
