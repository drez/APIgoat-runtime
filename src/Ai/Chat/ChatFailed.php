<?php

namespace ApiGoat\Ai\Chat;

/**
 * The model did not answer (transport error, non-2xx, empty completion).
 * Carries the HTTP status the driver saw so an endpoint can map it (0 → 502).
 */
final class ChatFailed extends \RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
