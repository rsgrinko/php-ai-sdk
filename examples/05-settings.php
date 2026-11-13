<?php

declare(strict_types=1);

/**
 * Пример 5. Настройки агента.
 *
 * Показывает то, что нельзя увидеть в простом примере:
 *   - tool_choice: заставить модель вызвать инструмент;
 *   - toolApproval: запретить опасный вызов и посмотреть, что ответит модель;
 *   - historyLimit: ограничение истории (здесь просто печатаем размер).
 *
 * Инструменты: get_time, calculator.
 * Флаги: toolChoice=required, toolApproval, maxRounds, historyLimit.
 *
 * Важно: не все локальные серверы (llama.cpp, Ollama) поддерживают
 * tool_choice=required — тогда флаг молча игнорируется, и модель отвечает
 * текстом. Пример это честно показывает.
 *
 * Запуск:
 *   php examples/05-settings.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\ToolRegistry;
use Rsgrinko\AiSdk\Tools\CalculatorTool;
use Rsgrinko\AiSdk\Tools\TimeTool;

$tools = (new ToolRegistry())->add(new TimeTool(), new CalculatorTool());
$client = AiClient::fromEnv();

// --- 1. tool_choice: 'required' — модель обязана вызвать хоть что-то --------
// Вопрос не требует инструментов, но настройка заставляет модель действовать.

$forced = new Agent($client, $tools, 'Отвечай одной строкой.', 3, 'required');

echo "1) tool_choice=required\n";
echo '   ответ: ' . $forced->ask('Поздоровайся со мной.') . PHP_EOL;
echo '   вызовов: ' . count($forced->trace()) . PHP_EOL;

if ($forced->trace() === []) {
    echo '   (провайдер проигнорировал tool_choice=required)' . PHP_EOL;
}

echo PHP_EOL;

// --- 2. toolApproval: запрет вызова ----------------------------------------
// Колбэк получает имя и аргументы. false — инструмент не выполняется,
// модель получает текст отказа и пытается ответить иначе.

$blocked = new Agent(
    client: $client,
    tools: $tools,
    systemPrompt: 'Отвечай одной строкой.',
    maxRounds: 3,
    toolApproval: static function (string $name, array $arguments): bool {
        echo '   запрос: ' . $name . ' ' . json_encode($arguments, JSON_UNESCAPED_UNICODE) . PHP_EOL;

        // Разрешаем только калькулятор, «время» считаем запрещённым.
        return $name === 'calculator';
    },
);

echo "2) toolApproval: get_time запрещён\n";
echo '   ответ: ' . $blocked->ask('Который час в Москве?') . PHP_EOL;

foreach ($blocked->trace() as $step) {
    echo '   ' . $step['name'] . ' → ' . ($step['blocked'] ? 'ЗАПРЕЩЁН' : $step['output']) . PHP_EOL;
}

// --- 3. historyLimit -------------------------------------------------------
// В .env это AI_HISTORY_LIMIT. Обрезает старые ходы, system и текущий
// вопрос остаются. Ноль — не обрезать.

echo PHP_EOL . "3) размер истории после двух вопросов\n";
$memory = new Agent($client, $tools, 'Отвечай одним словом.', 3, 'auto', true, 40);
$memory->ask('Меня зовут Роман.');
$memory->ask('Как меня зовут?');
echo '   сообщений в истории: ' . count($memory->messages()) . ' (лимит 40)' . PHP_EOL;


