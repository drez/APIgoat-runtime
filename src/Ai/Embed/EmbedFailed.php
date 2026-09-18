<?php

namespace ApiGoat\Ai\Embed;

/**
 * The provider did not return usable vectors.
 *
 * Carries the HTTP status the driver saw AND whether retrying could help,
 * because a backfill loop over tens of thousands of rows has to tell the two
 * apart: a transient failure is re-queued, a permanent one is a bug or a
 * misconfiguration and must stop the run instead of burning the corpus
 * against a wrong model name.
 *
 *   transient — no HTTP response (0), 408, 429, any 5xx (the box is busy,
 *               loading the model, or restarting)
 *   permanent — 4xx other than those (bad request, unknown model, no auth),
 *               an unconfigured model, a dimension mismatch
 */
final class EmbedFailed extends \RuntimeException
{
    private int $httpStatus;
    private bool $transient;

    /**
     * @param bool|null $transient null derives it from $httpStatus
     */
    public function __construct(string $message, int $httpStatus = 0, ?bool $transient = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->transient = $transient ?? self::transientFor($httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Retrying this exact call could succeed. */
    public function transient(): bool
    {
        return $this->transient;
    }

    /** The status-only rule, exposed so a caller can classify without an exception. */
    public static function transientFor(int $httpStatus): bool
    {
        if ($httpStatus === 0) {
            return true; // transport error or timeout
        }
        if ($httpStatus === 408 || $httpStatus === 429) {
            return true;
        }

        return $httpStatus >= 500;
    }
}
