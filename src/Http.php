<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Минимальный HTTP-клиент для инструментов: один GET, таймаут, проверка TLS.
 *
 * POST не нужен — для инструментов хватает чтения. Если понадобится,
 * добавьте рядом метод post() по образцу.
 */
final class Http
{
    /**
     * @return array{0: int, 1: string} HTTP-код и тело ответа
     */
    public static function get(string $url, float $timeout = 10.0): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new AiException('не удалось создать cURL-дескриптор');
        }

        // Тот же флаг, что и у AiClient: AI_VERIFY_SSL / AI_CAINFO.
        $verify = Env::bool('AI_VERIFY_SSL', true);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_USERAGENT => 'ai-sdk/1.0 (+https://example.local)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];

        $caInfo = Env::get('AI_CAINFO', '') ?? '';

        if ($caInfo !== '') {
            $options[CURLOPT_CAINFO] = $caInfo;
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new AiException('HTTP-ошибка: ' . ($error !== '' ? $error : 'неизвестно'));
        }

        return [$status, (string) $body];
    }
}
