<?php

namespace ApiGoat\Mail;

/**
 * What one fetchHeaders() call produced.
 *
 *  - headers   normalised header records ({@see HeaderRecord::KEYS}), oldest first
 *  - cursor    the state to persist ONLY AFTER these rows are committed
 *  - complete  false ⇒ call again with $cursor, there is more
 *  - coldStart true  ⇒ the provider cursor was unusable (IMAP UIDVALIDITY
 *              changed, Gmail historyId expired, or no cursor yet) and the
 *              connector fell back to a bounded window; $coldStartReason says
 *              why. Callers must surface this loudly (mailbox.status='Error'
 *              or a warning) — mail outside the window is NOT coming.
 */
final class FetchResult
{
    public const REASON_INITIAL             = 'initial';
    public const REASON_UIDVALIDITY_CHANGED = 'uidvalidity_changed';
    public const REASON_HISTORY_EXPIRED     = 'history_expired';

    /** @param array<int,array<string,mixed>> $headers */
    public function __construct(
        public readonly array $headers,
        public readonly MailboxState $cursor,
        public readonly bool $complete = true,
        public readonly bool $coldStart = false,
        public readonly ?string $coldStartReason = null,
    ) {
    }

    public function count(): int
    {
        return count($this->headers);
    }
}
