<?php

declare(strict_types=1);

/**
 * Пример 12. Полный набор инструментов и все флаги.
 *
 * Инструменты: все 18. Блок регистрации ниже — шпаргалка «что где включается».
 * Флаги: у каждого инструмента свои, перечислены по группам.
 *
 * ВНИМАНИЕ: здесь включены write_file и run_command. В реальном проекте
 * регистрируйте только нужное и ограничивайте песочницу, allow и workdir.
 *
 * Запуск:
 *   php examples/12-all-tools.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\Agent;
use AiSdk\AiClient;
use AiSdk\ToolRegistry;
use AiSdk\Tools\CalculatorTool;
use AiSdk\Tools\ConvertUnitsTool;
use AiSdk\Tools\CsvQueryTool;
use AiSdk\Tools\DateCalcTool;
use AiSdk\Tools\FileSandbox;
use AiSdk\Tools\FindFilesTool;
use AiSdk\Tools\FinishTool;
use AiSdk\Tools\HashTool;
use AiSdk\Tools\HttpRequestTool;
use AiSdk\Tools\JsonQueryTool;
use AiSdk\Tools\ListDirTool;
use AiSdk\Tools\ReadFileTool;
use AiSdk\Tools\ReplaceInFileTool;
use AiSdk\Tools\SearchFilesTool;
use AiSdk\Tools\ShellTool;
use AiSdk\Tools\TimeTool;
use AiSdk\Tools\UuidTool;
use AiSdk\Tools\WeatherTool;
use AiSdk\Tools\WriteFileTool;

$projectRoot = dirname(__DIR__);
$sandbox = new FileSandbox($projectRoot);

$tools = (new ToolRegistry())->add(
    // --- Без настроек -----------------------------------------------------
    new TimeTool(),
    new CalculatorTool(),
    new WeatherTool(),
    new ConvertUnitsTool(),
    new DateCalcTool(),
    new UuidTool(),
    new HashTool(),
    new JsonQueryTool(),
    new FinishTool(),

    // --- HTTP: SSRF включён, креды задаются по хосту ----------------------
    new HttpRequestTool(
        auth: [],              // ['api.example.com' => ['user' => '...', 'pass' => '...']]
        allowPrivate: false,   // true — разрешить внутреннюю сеть
    ),

    // --- Файлы: ограниченная песочница на корень проекта -------------------
    new ReadFileTool($sandbox),
    new ListDirTool($sandbox),
    new WriteFileTool($sandbox),
    new ReplaceInFileTool($sandbox),
    new SearchFilesTool($sandbox),
    new FindFilesTool($sandbox),
    new CsvQueryTool($sandbox),

    // --- Опасно: оболочка с allowlist и фиксированным каталогом -----------
    new ShellTool(
        workdir: $projectRoot,
        shell: 'auto',          // bash | sh | cmd | powershell | pwsh
        timeout: 10.0,
        maxOutput: 8000,
        allow: ['php'],         // только первое слово команды
        tmpDir: __DIR__ . '/var',
    ),
);

$agent = new Agent(
    // 18 схем инструментов — это заметный промпт: локальной модели нужно время
    // на prefill, поэтому таймаут поднят выше AI_TIMEOUT по умолчанию.
    client: AiClient::fromEnv(timeout: 120.0),
    tools: $tools,
    systemPrompt: 'Отвечай кратко. Используй инструменты, когда нужен факт.',
    maxRounds: 3,
    toolChoice: 'auto',
    parallelToolCalls: true,
    historyLimit: 40,
    // Опасные инструменты можно запретить на уровне агента, не убирая из реестра.
    toolApproval: static fn (string $name, array $args): bool => $name !== 'run_command',
    finishTool: 'finish',
);

echo 'инструментов в реестре: ' . count($tools->all()) . PHP_EOL;

// Сетевые ошибки приходят исключением — их стоит показывать пользователю.
try {
    echo $agent->ask('Сколько сейчас времени и сколько будет 15% от 2000?') . PHP_EOL;
} catch (Throwable $e) {
    echo 'Ошибка запроса к модели: ' . $e->getMessage() . PHP_EOL;
}

foreach ($agent->trace() as $step) {
    echo '  ' . $step['name'] . ' → '
        . mb_substr(str_replace("\n", ' | ', $step['output']), 0, 100)
        . ($step['blocked'] ? ' (запрещён)' : '') . PHP_EOL;
}
