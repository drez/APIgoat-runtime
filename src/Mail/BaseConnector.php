<?php

namespace ApiGoat\Mail;

/**
 * Default "not implemented" bodies for the phase-2/3 methods so a connector
 * only overrides what it can do, and capabilities() stays honest by
 * construction: override {@see capabilities()} to advertise what you added.
 */
abstract class BaseConnector implements MailConnector
{
    public function capabilities(): array
    {
        return [self::CAP_LIST_FOLDERS, self::CAP_FETCH_BODY];
    }

    public function markRead(string $providerId, bool $read): void
    {
        throw $this->unsupported('markRead');
    }

    public function move(string $providerId, string $folder): string
    {
        throw $this->unsupported('move');
    }

    public function trash(string $providerId): string
    {
        throw $this->unsupported('trash');
    }

    public function send(array $message): string
    {
        throw $this->unsupported('send');
    }

    protected function unsupported(string $op): UnsupportedOperation
    {
        return new UnsupportedOperation(static::class . " does not support {$op}()");
    }

    /** Guard shared by every connector: $max must be a positive window. */
    protected static function clampMax(int $max, int $ceiling = 500): int
    {
        return max(1, min($max, $ceiling));
    }
}
