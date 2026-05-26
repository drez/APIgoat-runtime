<?php

namespace ApiGoat\Storage\Drive\Exceptions;

/**
 * 5xx / network timeout / cURL error. Retryable with exponential backoff;
 * surfaces the underlying HTTP code (or 0 for transport errors) for logging.
 */
class TransientError extends \RuntimeException
{
    public int $httpCode;

    public function __construct(string $message, int $httpCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
    }
}
