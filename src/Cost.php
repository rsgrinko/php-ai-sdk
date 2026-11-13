<?php

declare(strict_types=1);

namespace Rsgrinko\AiSdk;

/**
 * Оценка стоимости запросов по тарифу провайдера.
 *
 * Тарифы не зашиты: они меняются и зависят от модели, поэтому задаются
 * явно. Цены — в долларах за 1 миллион токенов.
 *
 *   $cost = (new Cost())
 *       ->withRate('deepseek-chat', input: 0.27, output: 1.10);
 *
 *   echo $cost->format($cost->estimate('deepseek-chat', $agent->usage()));
 *
 * Если модель не описана — estimate() вернёт null (а format() — «—»):
 * лучше честное «неизвестно», чем выдуманное число.
 */
final class Cost
{
    /** @param array<string, array{input: float, output: float}> $rates USD за 1M токенов */
    public function __construct(private readonly array $rates = [])
    {
    }

    /**
     * Добавить тариф модели. Возвращает новый экземпляр.
     */
    public function withRate(string $model, float $input, float $output): self
    {
        $rates = $this->rates;
        $rates[$model] = ['input' => $input, 'output' => $output];

        return new self($rates);
    }

    /**
     * @param array{0?: int, prompt_tokens?: int, completion_tokens?: int} $usage
     */
    public function estimate(string $model, array $usage): ?float
    {
        $rate = $this->rates[$model] ?? null;

        if ($rate === null) {
            return null;
        }

        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);

        return ($prompt * $rate['input'] + $completion * $rate['output']) / 1_000_000;
    }

    public function format(?float $usd, int $precision = 6): string
    {
        if ($usd === null) {
            return '—';
        }

        if ($usd === 0.0) {
            return '$0';
        }

        return '$' . rtrim(rtrim(number_format($usd, $precision, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, array{input: float, output: float}>
     */
    public function rates(): array
    {
        return $this->rates;
    }
}
