<?php

namespace ApiGoat\Mail;

/**
 * One mailbox, one provider, one contract.
 *
 * Phase 1 uses capabilities() / verify() / listFolders() / fetchHeaders() /
 * fetchBody(). The phase-2 mutations and the phase-3 send() are declared now
 * so callers never reshape; {@see BaseConnector} throws
 * {@see UnsupportedOperation} for whatever a connector has not implemented,
 * and capabilities() says so up front.
 *
 * Failure taxonomy (the job queue already treats each correctly):
 *   ApiGoat\Sync\Exceptions\AuthFailed     credentials/consent — do not retry blindly
 *   ApiGoat\Sync\Exceptions\RateLimited    back off (code = Retry-After seconds when known)
 *   ApiGoat\Sync\Exceptions\TransientError network / 5xx — retry
 *   UnsupportedOperation                   caller bug or provider limit — never retry
 */
interface MailConnector
{
    public const CAP_LIST_FOLDERS = 'list_folders';
    public const CAP_FETCH_BODY   = 'fetch_body';
    public const CAP_THREADS      = 'threads';
    public const CAP_LABELS       = 'labels';
    public const CAP_MARK_READ    = 'mark_read';
    public const CAP_MOVE         = 'move';
    public const CAP_TRASH        = 'trash';
    public const CAP_SEND         = 'send';

    /** @return array<int,string> the CAP_* values this connector really supports */
    public function capabilities(): array;

    /** Connect + authenticate + touch the configured folder. Throws on any problem, returns nothing on success. */
    public function verify(): void;

    /**
     * @return array<int,array{id:string, name:string, type?:string}> id is what fetchHeaders()/move() take as $folder
     */
    public function listFolders(): array;

    /**
     * Headers newer than $cursor in $folder, at most $max, oldest first.
     * A null cursor — or one the provider no longer honours — is a cold start
     * bounded to the connector's window and flagged on the result.
     */
    public function fetchHeaders(string $folder, ?MailboxState $cursor, int $max): FetchResult;

    public function fetchBody(string $providerId): MailBody;

    // ---- phase 2 (declared now; BaseConnector throws UnsupportedOperation) ----

    public function markRead(string $providerId, bool $read): void;

    /** @return string the provider id after the move — IMAP MOVE reassigns the UID, so it may differ */
    public function move(string $providerId, string $folder): string;

    /** @return string the provider id after trashing (see move()) */
    public function trash(string $providerId): string;

    // ---- phase 3 ----

    /**
     * @param array{to:array<int,string>|string, cc?:array<int,string>|string, bcc?:array<int,string>|string,
     *               subject:string, text?:string, html?:string, in_reply_to?:string, references?:string,
     *               from?:string} $message
     * @return string provider id of the sent message
     */
    public function send(array $message): string;
}
