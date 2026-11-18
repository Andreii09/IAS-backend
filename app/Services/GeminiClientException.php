<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Thrown for configuration or provider problems. The chat controller maps
 * this (and any other runtime failure) to a friendly 503 response.
 */
class GeminiClientException extends RuntimeException
{
}
