<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Thrown for configuration or provider problems. The chat controller maps
 * this (and any other runtime failure) to labelled offline help.
 */
class GeminiClientException extends RuntimeException
{
    /** The circuit breaker is open; no provider call was attempted. */
    public const CIRCUIT_OPEN = 2;
}
