<?php

declare(strict_types=1);

/**
 * Пример 16. Многоходовый диалог: память, смена роли, сброс, стоимость.
 *
 * Инструменты: нет.
 * Флаги: setSystemPrompt(), messages(), usage(), reset(), Cost.
 *
 * Agent помнит историю между ask(): второй вопрос опирается на первый.
 * setSystemPrompt() меняет роль, не теряя историю. reset() начинает заново.
 *
 * Запуск:
 *   php examples/16-dialog.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Rsgrinko\AiSdk\Agent;
use Rsgrinko\AiSdk\AiClient;
use Rsgrinko\AiSdk\Cost;
use Rsgrinko\AiSdk\Env;

$agent = new Agent(
    client: AiClient::fromEnv(timeout: 120.0),
    systemPrompt: 'Отвечай одним коротким предложением.',
);

echo '1) память между вопросами' . PHP_EOL;
echo '> ' . $agent->ask('Меня зовут Роман, я пишу на PHP.') . PHP_EOL;
echo '> ' . $agent->ask('Как меня зовут и на каком языке я пишу?') . PHP_EOL;

echo PHP_EOL . '2) смена роли без потери истории' . PHP_EOL;
$agent->setSystemPrompt('Answer in one short sentence, in English only.');
echo '> ' . $agent->ask('Как меня зовут?') . PHP_EOL;
echo '   сообщений в истории: ' . count($agent->messages()) . PHP_EOL;

// Тариф условный: у локальной модели цены нет, но так видно, как считать.
$model = Env::get('AI_MODEL', 'deepseek-chat') ?? 'deepseek-chat';
$cost = (new Cost())->withRate($model, input: 0.27, output: 1.10);
$usage = $agent->usage();

echo PHP_EOL . '3) токены и цена (условный тариф $0.27 / $1.10 за 1M)' . PHP_EOL;
echo '   ' . $usage['prompt_tokens'] . ' → ' . $usage['completion_tokens']
    . ', ≈ ' . $cost->format($cost->estimate($model, $usage)) . PHP_EOL;

echo PHP_EOL . '4) reset(): история очищена' . PHP_EOL;
$agent->reset();
$agent->setSystemPrompt('Отвечай одним коротким предложением. Если не знаешь — так и скажи.');
echo '> ' . $agent->ask('Как меня зовут?') . PHP_EOL;
