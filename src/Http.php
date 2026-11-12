<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Минимальный HTTP-клиент без зависимостей.
 *
 * get() — простой GET с редиректами (используется инструментами вроде погоды).
 * request() — низкоуровневый вариант: без авто-редиректов, с лимитом размера
 * и заголовком Location. На нём строится http_get с защитой от SSRF.
 */
final class Http
{
    /**
     * @return array{0: int, 1: string} HTTP-код и тело ответа
     */
    public static function get(string $url, float $timeout = 10.0): array
    {
        $response = self::request($url, $timeout, true);

        return [$response['status'], $response['body']];
    }

    /**
     * @param bool $follow   следовать редиректам автоматически
     * @param int $maxBytes  предел тела ответа; больше — обрезаем и обрываем загрузку
     * @return array{status: int, body: string, location: string|null, truncated: bool}
     */
    public static function request(
        string $url,
        float $timeout = 10.0,
        bool $follow = false,
        int $maxBytes = 262144,
    ): array {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new AiException('не удалось создать cURL-дескриптор');
        }

        $body = '';
        $truncated = false;
        $location = null;
        $limit = max(1024, $maxBytes);

        $verify = Env::bool('AI_VERIFY_SSL', true);

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_USERAGENT => 'ai-sdk/1.0 (+https://example.local)',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$truncated, $limit): int {
                $room = $limit - strlen($body);

                if (strlen($chunk) > $room) {
                    $body .= substr($chunk, 0, $room);
                    $truncated = true;

                    return 0; // прерываем загрузку
                }

                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
        ];

        $caInfo = Env::get('AI_CAINFO', '') ?? '';

        if ($caInfo !== '') {
            $options[CURLOPT_CAINFO] = $caInfo;
        }

        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // 23 = CURLE_WRITE_ERROR: это мы сами оборвали слишком большой ответ.
        if ($errno !== 0 && !($truncated && $errno === 23)) {
            throw new AiException('HTTP-ошибка: ' . ($error !== '' ? $error : 'код ' . $errno));
        }

        return [
            'status' => $status,
            'body' => $body,
            'location' => $location,
            'truncated' => $truncated,
        ];
    }
}
