<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\HttpTransport;
use AiSdk\Tool;

/**
 * Пример инструмента с обращением к внешнему API.
 *
 * open-meteo.com не требует ключа: сначала геокодинг по названию города,
 * потом текущая погода по координатам.
 */
final class WeatherTool implements Tool
{
    private const WEATHER_CODES = [
        0 => 'ясно',
        1 => 'в основном ясно',
        2 => 'переменная облачность',
        3 => 'облачно',
        45 => 'туман',
        48 => 'изморозь',
        51 => 'слабая морось',
        53 => 'морось',
        55 => 'сильная морось',
        61 => 'слабый дождь',
        63 => 'дождь',
        65 => 'сильный дождь',
        66 => 'ледяной дождь',
        67 => 'сильный ледяной дождь',
        71 => 'слабый снег',
        73 => 'снег',
        75 => 'сильный снег',
        77 => 'снежная крупа',
        80 => 'ливень',
        81 => 'сильный ливень',
        82 => 'очень сильный ливень',
        85 => 'снегопад',
        86 => 'сильный снегопад',
        95 => 'гроза',
        96 => 'гроза с градом',
        99 => 'сильная гроза с градом',
    ];

    public function name(): string
    {
        return 'get_weather';
    }

    public function description(): string
    {
        return 'Текущая погода в городе: температура, ощущаемая температура, влажность, ветер, осадки.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'city' => [
                    'type' => 'string',
                    'description' => 'Название города, например Тула или Moscow.',
                ],
            ],
            'required' => ['city'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $city = trim((string) ($args['city'] ?? ''));

        if ($city === '') {
            return 'Не указан город.';
        }

        $place = $this->geocode($city);

        if ($place === null) {
            return 'Город не найден: ' . $city . '.';
        }

        $query = http_build_query([
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,wind_speed_10m,weather_code',
            'timezone' => 'auto',
        ]);

        [$status, $body] = HttpTransport::get('https://api.open-meteo.com/v1/forecast?' . $query);
        $data = json_decode($body, true);
        $current = $data['current'] ?? null;

        if ($status >= 400 || !is_array($current)) {
            return 'Не удалось получить погоду (HTTP ' . $status . ').';
        }

        $code = (int) ($current['weather_code'] ?? 0);
        $description = self::WEATHER_CODES[$code] ?? ('код ' . $code);

        return sprintf(
            '%s: %s, %.1f °C (ощущается %.1f °C), влажность %d%%, ветер %.1f км/ч.',
            $place['label'],
            $description,
            (float) ($current['temperature_2m'] ?? 0.0),
            (float) ($current['apparent_temperature'] ?? 0.0),
            (int) ($current['relative_humidity_2m'] ?? 0),
            (float) ($current['wind_speed_10m'] ?? 0.0),
        );
    }

    /**
     * @return array{label: string, latitude: float, longitude: float}|null
     */
    private function geocode(string $city): ?array
    {
        $query = http_build_query([
            'name' => $city,
            'count' => 1,
            'language' => 'ru',
            'format' => 'json',
        ]);

        [$status, $body] = HttpTransport::get('https://geocoding-api.open-meteo.com/v1/search?' . $query);

        if ($status >= 400) {
            return null;
        }

        $data = json_decode($body, true);
        $first = $data['results'][0] ?? null;

        if (!is_array($first)) {
            return null;
        }

        $label = (string) ($first['name'] ?? $city);

        if (($first['country'] ?? '') !== '') {
            $label .= ', ' . $first['country'];
        }

        return [
            'label' => $label,
            'latitude' => (float) ($first['latitude'] ?? 0.0),
            'longitude' => (float) ($first['longitude'] ?? 0.0),
        ];
    }
}

