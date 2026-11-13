<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk;

/**
 * Повтор запроса к модели при временных сбоях.
 *
 * Повторяется:
 *   - HTTP 408, 425, 429, 500, 502, 503, 504, 529 — перегрузка и лимиты;
 *   - сетевая ошибка (соединение сброшено, DNS и т.п.).
 *
 * Не повторяется:
 *   - таймаут: модель просто долго думает, повтор утроит ожидание;
 *   - 400/401/403/404 — ошибка в запросе или ключе, повтор не поможет.
 *
 * Пауза растёт экспоненциально (base, 2·base, 4·base...) со случайным
 * разбросом, чтобы параллельные клиенты не били в API одновременно.
 * Retry-After от сервера важнее расчёта; если сервер просит ждать дольше
 * maxDelay — повтора нет, сразу возвращается ответ с ошибкой.
 *
 *   new AiClient(..., retry: new Retry(maxRetries: 3, baseDelay: 0.5));
 *   new AiClient(..., retry: Retry::none());
 */
final class Retry
{
    public const RETRYABLE_STATUS = [408, 425, 429, 500, 502, 503, 504, 529];

    /** CURLE_OPERATION_TIMEDOUT */
    private const CURL_TIMEOUT = 28;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param int $maxRetries повторов сверх первой попытки (0 — без повторов)
     * @param float $baseDelay пауза перед первым повтором, секунды
     * @param float $maxDelay верхняя граница паузы, секунды
     * @param (\Closure(float): void)|null $sleep как ждать; подменяется в тестах
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly float $baseDelay = 1.0,
        public readonly float $maxDelay = 30.0,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public static function none(): self
    {
        return new self(maxRetries: 0);
    }

    /**
     * Выполнить попытку, повторяя её при временных сбоях.
     *
     * @template T of array{status: int, retry_after?: string|null}
     * @param \Closure(): T $attempt
     * @return T последний ответ — успешный или с ошибкой, если повторы кончились
     * @throws AiException сетевая ошибка последней попытки
     */
    public function run(\Closure $attempt): array
    {
        for ($try = 0; ; $try++) {
            $canRetry = $try < $this->maxRetries;

            try {
                $response = $attempt();
            } catch (AiException $e) {
                if (!$canRetry || $e->getCode() === self::CURL_TIMEOUT) {
                    throw $e;
                }

                ($this->sleep)($this->backoff($try));

                continue;
            }

            if (!$canRetry || !in_array($response['status'], self::RETRYABLE_STATUS, true)) {
                return $response;
            }

            $delay = $this->delay($try, $response['retry_after'] ?? null);

            if ($delay === null) {
                return $response;
            }

            ($this->sleep)($delay);
        }
    }

    /**
     * Пауза перед повтором номер $try + 1 или null — не повторять,
     * потому что сервер просит ждать дольше maxDelay.
     */
    public function delay(int $try, ?string $retryAfter): ?float
    {
        $requested = self::parseRetryAfter($retryAfter);

        if ($requested === null) {
            return $this->backoff($try);
        }

        return $requested > $this->maxDelay ? null : $requested;
    }

    private function backoff(int $try): float
    {
        $delay = $this->baseDelay * (2 ** $try);
        $jitter = 0.5 + mt_rand() / mt_getrandmax() * 0.5;

        return min($this->maxDelay, $delay * $jitter);
    }

    /**
     * Retry-After бывает числом секунд или HTTP-датой.
     */
    private static function parseRetryAfter(?string $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        $time = strtotime($value);

        return $time === false ? null : max(0.0, (float) ($time - time()));
    }
}
