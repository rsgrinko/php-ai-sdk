<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk;

/**
 * Настройки клиента и агента — из массива, без .env и переменных окружения.
 *
 * Источник может быть любым: константы, конфиг фреймворка, база данных.
 *
 *   $config = [
 *       'api_key' => MY_AI_KEY,
 *       'model' => 'deepseek-chat',
 *       'max_rounds' => 8,
 *   ];
 *
 *   $client = AiClient::fromArray($config);
 *   $agent = Agent::fromArray($config, $tools);
 *
 * Три способа собрать клиент и агента:
 *   fromEnv()        — только переменные окружения AI_* (и .env);
 *   fromArray($c)    — только массив, окружение не читается;
 *   create($c = [])  — массив, недостающее — из окружения, остальное — по умолчанию.
 *
 * Один массив годится для обоих: клиенту не мешают настройки агента и наоборот.
 * Неизвестный ключ — исключение: опечатка не должна молча превращаться в
 * значение по умолчанию.
 *
 * Значения можно передавать строками ("true", "30") — как из .env — или
 * сразу нужного типа.
 */
final class Config
{
    /**
     * Ключ массива => переменная окружения. Список всех настроек.
     */
    public const ENV = [
        // клиент
        'api_key' => 'AI_API_KEY',
        'model' => 'AI_MODEL',
        'base_url' => 'AI_BASE_URL',
        'timeout' => 'AI_TIMEOUT',
        'temperature' => 'AI_TEMPERATURE',
        'max_tokens' => 'AI_MAX_TOKENS',
        'disable_thinking' => 'AI_DISABLE_THINKING',
        'verify_ssl' => 'AI_VERIFY_SSL',
        'ca_info' => 'AI_CAINFO',
        'max_retries' => 'AI_MAX_RETRIES',
        // агент
        'max_rounds' => 'AI_MAX_ROUNDS',
        'tool_choice' => 'AI_TOOL_CHOICE',
        'parallel_tool_calls' => 'AI_PARALLEL_TOOL_CALLS',
        'history_limit' => 'AI_HISTORY_LIMIT',
        'finish_tool' => 'AI_FINISH_TOOL',
    ];

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, mixed> $values
     * @throws \InvalidArgumentException если есть неизвестный ключ
     */
    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), array_keys(self::ENV));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Неизвестная настройка: ' . implode(', ', $unknown)
                . '. Доступны: ' . implode(', ', array_keys(self::ENV)) . '.'
            );
        }

        return new self($values);
    }

    /**
     * Всё сразу: значение из массива, если его там нет — из окружения,
     * если и там нет — значение по умолчанию. Для create().
     *
     * @param array<string, mixed> $values
     * @throws \InvalidArgumentException если есть неизвестный ключ
     */
    public static function resolve(array $values = []): self
    {
        return new self(self::fromArray($values)->values + self::fromEnv()->values);
    }

    /**
     * Настройки из переменных окружения (и .env, если его загрузили Env::load).
     * Пустые переменные пропускаются — для них берутся значения по умолчанию.
     */
    public static function fromEnv(): self
    {
        $values = [];

        foreach (self::ENV as $key => $variable) {
            $value = Env::get($variable);

            if ($value !== null && $value !== '') {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    public function string(string $key, string $default): string
    {
        $value = $this->values[$key] ?? null;

        return $value === null ? $default : (string) $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
