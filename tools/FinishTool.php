<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;

/**
 * Явное завершение работы.
 *
 * Модель вызывает finish, когда задача выполнена, и кладёт финальный ответ
 * в аргумент result. Agent видит этот вызов, останавливает цикл и возвращает
 * result как ответ — без лишнего запроса к модели.
 *
 * Зачем это нужно, если модель и так может ответить текстом:
 *   - ответ всегда в известном месте (аргумент, а не свободный текст);
 *   - с tool_choice=required модель ОБЯЗАНА вернуть результат через вызов;
 *   - видно в trace, что работа действительно завершена, а не оборвалась;
 *   - удобно для классификации, извлечения данных и любых задач,
 *     где нужен предсказуемый формат.
 *
 * Если агент не настроен на finish (finishTool: ''), инструмент работает
 * как обычный и просто возвращает текст.
 */
final class FinishTool implements Tool
{
    public function name(): string
    {
        return 'finish';
    }

    public function description(): string
    {
        return 'Завершить работу и вернуть финальный ответ. Вызывай, когда задача выполнена: '
            . 'готовый ответ пользователю положи в result. '
            . 'Не вызывай, пока не собрал нужные данные.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'result' => [
                    'type' => 'string',
                    'description' => 'Финальный ответ или результат задачи.',
                ],
            ],
            'required' => ['result'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        // Сюда управление доходит, только если Agent не перехватывает finish.
        $result = trim((string) ($args['result'] ?? ''));

        return $result !== '' ? $result : 'Задача завершена.';
    }
}
