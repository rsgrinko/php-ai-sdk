<?php

declare(strict_types=1);

namespace AiSdk;

/**
 * Любая ошибка обращения к модели: сеть, HTTP 4xx/5xx, неожиданный ответ.
 */
final class AiException extends \RuntimeException
{
}
