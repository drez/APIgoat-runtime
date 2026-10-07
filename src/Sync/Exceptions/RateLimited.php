<?php

namespace ApiGoat\Sync\Exceptions;

/**
 * Provider throttled us — retry later. getCode() is the Retry-After seconds (0/unknown allowed);
 * httpStatus() is the HTTP status that produced it (0 when not from an HTTP answer), so a caller
 * can tell a 429 (request refused) from a 503 (request may have been processed).
 */
final class RateLimited extends \RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, int $httpStatus = 0)
    {
        parent::__construct($message, $code, $previous);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
