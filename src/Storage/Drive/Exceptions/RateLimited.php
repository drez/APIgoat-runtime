<?php

namespace ApiGoat\Storage\Drive\Exceptions;

/**
 * 429 / 403 userRateLimitExceeded. Retryable with exponential backoff.
 * Honors $retryAfterSeconds if Google sent a Retry-After header.
 */
class RateLimited extends \RuntimeException
{
    public int $retryAfterSeconds;

    public function __construct(string $message, int $retryAfterSeconds = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 429, $previous);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }
}
