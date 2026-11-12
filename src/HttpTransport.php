<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Минимальный HTTP-клиент без зависимостей.
 *
 * get() — простой GET с редиректами (инструменты вроде погоды).
 * request() — низкоуровневый вызов: метод, заголовки, тело, лимит размера.
 * На нём построен http_request, включая защиту от SSRF.
 */
final class HttpTransport
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
     * Подсказка при типовой ошибке TLS на Windows.
     */
    private static function tlsHint(string $error, bool $verify): string
    {
        if (!$verify) {
            return '';
        }

        if (!str_contains($error, 'certificate') && !str_contains($error, 'SSL')) {
            return '';
        }

        return ' Подсказка: укажите путь к корневым сертификатам в AI_CAINFO '
            . 'или временно поставьте AI_VERIFY_SSL=false (только локально).';
    }

    /**
     * Привести заголовки к формату cURL: список строк "Имя: значение".
     *
     * Принимает и карту ['X-Trace' => 'abc'], и уже готовый список строк.
     * Пустой список заменяется на Accept по умолчанию.
     *
     * @param array<int|string, string> $headers
     * @return list<string>
     */
    public static function headerLines(array $headers): array
    {
        if ($headers === []) {
            return ['Accept: */*'];
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * @param bool $follow   следовать редиректам автоматически
     * @param int $maxBytes  предел тела ответа; больше — обрезаем и обрываем загрузку
     * @param array<string, string> $headers
     * @return array{status: int, body: string, location: string|null, content_type: string|null, truncated: bool}
     */
    public static function request(
        string $url,
        float $timeout = 10.0,
        bool $follow = false,
        int $maxBytes = 262144,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
    ): array {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new AiException('не удалось создать cURL-дескриптор');
        }

        $body = $body ?? '';
        $response = '';
        $truncated = false;
        $location = null;
        $contentType = null;
        $limit = max(1024, $maxBytes);

        $verify = Env::bool('AI_VERIFY_SSL', true);

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_USERAGENT => 'ai-sdk/1.0 (+https://example.local)',
            CURLOPT_HTTPHEADER => self::headerLines($headers),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$truncated, $limit): int {
                $room = $limit - strlen($response);

                if (strlen($chunk) > $room) {
                    $response .= substr($chunk, 0, $room);
                    $truncated = true;

                    return 0; // прерываем загрузку
                }

                $response .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$contentType): int {
                $lower = strtolower($line);

                if (str_starts_with($lower, 'location:')) {
                    $location = trim(substr($line, 9));
                } elseif (str_starts_with($lower, 'content-type:')) {
                    $contentType = trim(substr($line, 13));
                }

                return strlen($line);
            },
        ];

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

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
            throw new AiException(
                'HTTP-ошибка: ' . ($error !== '' ? $error : 'код ' . $errno)
                . self::tlsHint($error, $verify)
            );
        }

        return [
            'status' => $status,
            'body' => $response,
            'location' => $location,
            'content_type' => $contentType,
            'truncated' => $truncated,
        ];
    }
}

