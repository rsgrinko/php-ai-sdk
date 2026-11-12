<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Клиент любого OpenAI-совместимого /chat/completions.
 *
 * Годится для DeepSeek, OpenAI, OpenRouter, Ollama, llama.cpp, LM Studio.
 * Меняется только AI_BASE_URL и AI_MODEL в .env.
 */
final class AiClient implements ChatClient
{
    /**
     * @param array<string, mixed> $extraBody дополнительные поля тела запроса,
     *                                        если провайдер их требует
     * @param bool   $verifySsl проверять TLS-сертификат (по умолчанию да).
     *                          false — только для локальной отладки.
     * @param string $caInfo    путь к своему CA-бандлу (cacert.pem). Пусто —
     *                          системные сертификаты PHP/OpenSSL.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'deepseek-chat',
        private readonly string $baseUrl = 'https://api.deepseek.com',
        private readonly float $timeout = 30.0,
        private readonly float $temperature = 0.7,
        private readonly int $maxTokens = 1000,
        private readonly array $extraBody = [],
        private readonly bool $verifySsl = true,
        private readonly string $caInfo = '',
    ) {
    }

    /**
     * Собрать клиент из .env. Все параметры необязательны, кроме ключа
     * для закрытых провайдеров.
     */
    public static function fromEnv(): self
    {
        $extraBody = [];

        // DeepSeek умеет отключать «размышления» — так ответ быстрее и дешевле.
        if (Env::bool('AI_DISABLE_THINKING', false)) {
            $extraBody['thinking'] = ['type' => 'disabled'];
        }

        return new self(
            apiKey: Env::get('AI_API_KEY', '') ?? '',
            model: Env::get('AI_MODEL', 'deepseek-chat') ?? 'deepseek-chat',
            baseUrl: Env::get('AI_BASE_URL', 'https://api.deepseek.com') ?? 'https://api.deepseek.com',
            timeout: Env::float('AI_TIMEOUT', 30.0),
            temperature: Env::float('AI_TEMPERATURE', 0.7),
            maxTokens: Env::int('AI_MAX_TOKENS', 1000),
            extraBody: $extraBody,
            verifySsl: Env::bool('AI_VERIFY_SSL', true),
            caInfo: Env::get('AI_CAINFO', '') ?? '',
        );
    }

    public function chat(array $messages, array $tools = [], array $options = []): ChatResult
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => isset($options['temperature']) ? (float) $options['temperature'] : $this->temperature,
            'max_tokens' => isset($options['max_tokens']) ? (int) $options['max_tokens'] : $this->maxTokens,
        ];

        if ($tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = self::normalizeToolChoice($options['tool_choice'] ?? 'auto');

            if (array_key_exists('parallel_tool_calls', $options)) {
                $body['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
            }
        }

        $body = array_merge($body, $this->extraBody);

        [$raw, $status, $error] = $this->post($body);

        if ($raw === null) {
            throw new AiException('Ошибка сети: ' . $error . $this->tlsHint($error));
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new AiException(
                'Модель вернула не-JSON (HTTP ' . $status . '): ' . mb_substr($raw, 0, 300)
            );
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? $raw;
            throw new AiException('HTTP ' . $status . ': ' . mb_substr((string) $message, 0, 300));
        }

        $message = $data['choices'][0]['message'] ?? null;

        if (!is_array($message)) {
            throw new AiException('В ответе нет choices[0].message');
        }

        $text = is_string($message['content'] ?? null) ? $message['content'] : '';

        $assistant = ['role' => 'assistant', 'content' => $text];

        if (isset($message['tool_calls'])) {
            $assistant['tool_calls'] = $message['tool_calls'];
        }

        return new ChatResult(
            text: $text,
            toolCalls: self::parseToolCalls($message),
            assistantMessage: $assistant,
            usage: is_array($data['usage'] ?? null) ? $data['usage'] : [],
            raw: $data,
        );
    }

    /**
     * Разобрать tool_calls в удобный вид. Аргументы приходят строкой JSON.
     *
     * @param array<string, mixed> $message
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private static function parseToolCalls(array $message): array
    {
        $calls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            if (!is_array($call)) {
                continue;
            }

            $name = (string) ($call['function']['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $rawArguments = $call['function']['arguments'] ?? '{}';
            $arguments = is_string($rawArguments)
                ? json_decode($rawArguments, true)
                : $rawArguments;

            if (!is_array($arguments)) {
                $arguments = [];
            }

            $id = (string) ($call['id'] ?? '');

            $calls[] = [
                'id' => $id !== '' ? $id : 'call_' . bin2hex(random_bytes(6)),
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $calls;
    }

    /**
     * Привести tool_choice к формату OpenAI.
     *
     * @param string|array<string, mixed> $choice
     * @return string|array<string, mixed>
     */
    private static function normalizeToolChoice(string|array $choice): string|array
    {
        if (is_array($choice)) {
            return $choice;
        }

        if (in_array($choice, ['auto', 'none', 'required'], true)) {
            return $choice;
        }

        // Имя конкретного инструмента -> объект.
        return ['type' => 'function', 'function' => ['name' => $choice]];
    }

    /**
     * Подсказка при типовой ошибке TLS на Windows.
     */
    private function tlsHint(string $error): string
    {
        if (!$this->verifySsl) {
            return '';
        }

        if (!str_contains($error, 'certificate') && !str_contains($error, 'SSL')) {
            return '';
        }

        return ' Подсказка: укажите путь к корневым сертификатам в AI_CAINFO '
            . 'или временно поставьте AI_VERIFY_SSL=false (только локально).';
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string|null, 1: int, 2: string|null} тело, HTTP-код, ошибка
     */
    private function post(array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Expect:',
        ];

        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $payload = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($payload === false) {
            return [null, 0, 'не удалось сериализовать запрос в JSON'];
        }

        $ch = curl_init($url);

        if ($ch === false) {
            return [null, 0, 'не удалось создать cURL-дескриптор'];
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            // AI_VERIFY_SSL=false отключает проверку цепочки и имени хоста.
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        // Свой CA-бандл: актуально для Windows, где у PHP нет системных корней.
        if ($this->caInfo !== '') {
            $options[CURLOPT_CAINFO] = $this->caInfo;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [null, $status, $error !== '' ? $error : 'неизвестная ошибка cURL'];
        }

        return [(string) $raw, $status, null];
    }
}
