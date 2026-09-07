<?php

namespace ApiGoat\Mail;

/**
 * What one {@see MailConnector::fetchBefore()} call produced — one page of
 * mail HISTORY, walking backwards from the newest message the caller has
 * not read yet.
 *
 *  - headers   normalised header records ({@see HeaderRecord::KEYS}), oldest first
 *  - next      the opaque token to hand back for the NEXT (older) page, or
 *              null when there is nothing older; persist it only after the
 *              rows above are committed, exactly like a {@see FetchResult}
 *              cursor
 *  - complete  true ⇒ this page reached the bottom of the folder; the walk
 *              is done and the caller may stop asking
 *  - restarted true ⇒ the token the caller handed in no longer addresses
 *              anything on the provider (IMAP UIDVALIDITY changed) and the
 *              connector started again from the newest message. The caller
 *              has already-ingested rows coming back — harmless, the UNIQUE
 *              key swallows them — but the walk got longer, so say so.
 *
 * Note the asymmetry with FetchResult: a backfill never "cold starts". It is
 * ALWAYS a bounded walk of what is already on the server, so nothing can be
 * missed by falling back — only re-read.
 */
final class BackfillResult
{
    /** @param array<int,array<string,mixed>> $headers */
    public function __construct(
        public readonly array $headers,
        public readonly ?string $next,
        public readonly bool $complete,
        public readonly bool $restarted = false,
    ) {
    }

    public function count(): int
    {
        return count($this->headers);
    }
}
