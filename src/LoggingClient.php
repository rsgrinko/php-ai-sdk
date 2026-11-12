<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Декоратор ChatClient: пишет каждый запрос и ответ в лог.
 *
 *   $client = new LoggingClient(AiClient::fromEnv());
 *   $agent  = new Agent($client, $tools);
 *
 * Свой приёмник — колбэк, принимающий строку:
 *
 *   new LoggingClient($client, sink: fn (string $line) => $logger->info($line));
 *
 * Декоратор прозрачный: возвращает тот же ChatResult, а исключения
 * пробрасывает дальше, предварительно записав их.
 */
final class LoggingClient implements ChatClient
{
    /**
     * @param (\Closure(string): void)|null $sink куда писать строку; null — error_log()
     * @param bool $verbose печатать содержимое сообщений, инструментов и ответа
     */
    public function __construct(
        private readonly ChatClient $inner,
        private readonly ?\Closure $sink = null,
        private readonly bool $verbose = false,
    ) {
    }

    public function chat(array $messages, array $tools = [], array $options = []): ChatResult
    {
        $this->write(sprintf(
            'LLM → %d сообщ., %d инструментов%s',
            count($messages),
            count($tools),
            $options === [] ? '' : ', опции: ' . $this->json($options),
        ));

        if ($this->verbose) {
            foreach ($messages as $index => $message) {
                $this->write(sprintf(
                    '   [%d] %s: %s',
                    (int) $index,
                    (string) ($message['role'] ?? '?'),
                    mb_substr((string) ($message['content'] ?? ''), 0, 300),
                ));
            }

            if ($tools !== []) {
                $names = array_map(
                    static fn (array $tool): string => (string) ($tool['function']['name'] ?? '?'),
                    $tools
                );

                $this->write('   инструменты: ' . implode(', ', $names));
            }
        }

        $started = microtime(true);

        try {
            $result = $this->inner->chat($messages, $tools, $options);
        } catch (\Throwable $e) {
            $this->write(sprintf(
                'LLM ✗ %d мс: %s',
                $this->elapsed($started),
                $e->getMessage(),
            ));

            throw $e;
        }

        $usage = $result->usage;
        $summary = $result->hasToolCalls()
            ? 'tool_calls: ' . implode(', ', array_column($result->toolCalls, 'name'))
            : 'текст ' . mb_strlen($result->text) . ' симв.';

        $this->write(sprintf(
            'LLM ← %s, %d мс, токены %d/%d',
            $summary,
            $this->elapsed($started),
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        ));

        if ($this->verbose && $result->text !== '') {
            $this->write('   ответ: ' . mb_substr($result->text, 0, 300));
        }

        return $result;
    }

    private function write(string $line): void
    {
        if ($this->sink !== null) {
            ($this->sink)($line);

            return;
        }

        error_log($line);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
