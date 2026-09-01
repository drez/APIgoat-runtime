<?php

namespace ApiGoat\Mail\Imap;

/**
 * The thin slice of IMAP the connector needs, so the cursor / cold-start /
 * normalisation logic in {@see \ApiGoat\Mail\Connector\ImapConnector} is
 * testable with a fake and the library binding lives in ONE class
 * ({@see WebklexTransport}).
 *
 * Every method throws the runtime taxonomy (AuthFailed / RateLimited /
 * TransientError) — the adapter maps library exceptions, callers never see
 * library types.
 *
 * Header rows returned by {@see headers()} are raw-ish: the connector
 * normalises them via HeaderRecord. Shape:
 *   uid:int, message_id:string, in_reply_to:string, from:string (raw header),
 *   to:string, cc:string, subject:string, date:string|int|\DateTimeInterface,
 *   size:int, has_attachments:bool, seen:bool, flags:string[], snippet?:string,
 *   thread_id?:string (Gmail X-GM-THRID; '' or absent elsewhere)
 */
interface ImapTransport
{
    public function connect(): void;

    public function disconnect(): void;

    /** @return array<int,array{id:string, name:string, type?:string}> */
    public function folders(): array;

    /** @return array{uidvalidity:int, uidnext:int, exists:int} */
    public function status(string $folder): array;

    /**
     * UIDs in $folder, ascending. $minUid filters `UID >= $minUid`; $since
     * filters by INTERNALDATE (the cold-start bound). Either may be null.
     *
     * @return int[]
     */
    public function uids(string $folder, ?int $minUid, ?\DateTimeInterface $since): array;

    /**
     * @param int[] $uids
     * @return array<int,array<string,mixed>> keyed by uid (see the class doc for the row shape)
     */
    public function headers(string $folder, array $uids): array;

    /** The full RFC 822 message. */
    public function raw(string $folder, int $uid): string;

    public function setSeen(string $folder, int $uid, bool $seen): void;

    /** @return int the UID in $destination after the MOVE (0 when the server does not report it) */
    public function move(string $folder, int $uid, string $destination): int;

    /** \Deleted + expunge (used only when no Trash folder exists). */
    public function delete(string $folder, int $uid): void;
}
