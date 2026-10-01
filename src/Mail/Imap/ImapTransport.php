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
 *   thread_id?:string (Gmail X-GM-THRID; '' or absent elsewhere),
 *   auth_results?:string (the TOPMOST Authentication-Results value only, unfolded; '' when none)
 *   list_id?, list_unsubscribe?, precedence?, auto_submitted?: string (topmost occurrence; '' when none)
 */
interface ImapTransport
{
    public function connect(): void;

    public function disconnect(): void;

    /**
     * Every folder, flat (sub-folders included). `delimiter` is the server's
     * hierarchy separator for that folder ("/" or "." in practice) when the
     * transport knows it. `attributes` are the LIST flags exactly as the
     * server sent them ("\\Sent", "\\Noselect", …: RFC 3501 + RFC 6154
     * special-use). `subscribed` is LSUB membership, or null when the server
     * would not say.
     *
     * @return array<int,array{id:string, name:string, type?:string, delimiter?:string, attributes?:string[], subscribed?:bool|null}>
     */
    public function folders(): array;

    /** CREATE $path (a full path, already in the server's delimiter). */
    public function createFolder(string $path): void;

    /**
     * SUBSCRIBE $path. A folder that exists but is not subscribed is
     * invisible in Thunderbird and most desktop clients, which list only
     * subscribed folders (LSUB) by default.
     */
    public function subscribe(string $path): void;

    /**
     * APPEND $raw (full RFC 822 source) to $folder, flagged \Seen when $seen.
     *
     * @return int the new UID from the APPENDUID response code (RFC 4315 UIDPLUS), 0 when the server does not report it
     */
    public function append(string $folder, string $raw, bool $seen): int;

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

    /** The full RFC 822 message, fetched with BODY.PEEK (never touches \\Seen). */
    public function raw(string $folder, int $uid): string;

    /** The RFC 822 header block only (BODY.PEEK[HEADER]); no body, no attachments. */
    public function rawHeader(string $folder, int $uid): string;

    public function setSeen(string $folder, int $uid, bool $seen): void;

    /** The flags of one message ('Seen', 'Flagged', …), or null when $uid is not in $folder. */
    public function flags(string $folder, int $uid): ?array;

    public function setFlagged(string $folder, int $uid, bool $flagged): void;

    /**
     * UID COPY: the message stays in $folder. On Gmail over IMAP this is how a
     * message leaves All Mail for INBOX — it ADDS the label, where a MOVE
     * would expunge it from All Mail (which Gmail may treat as a delete).
     *
     * @return int the UID in $destination (0 when the server does not report COPYUID)
     */
    public function copy(string $folder, int $uid, string $destination): int;

    /** @return int the UID in $destination after the MOVE (0 when the server does not report it) */
    public function move(string $folder, int $uid, string $destination): int;

    /** \Deleted + expunge (used only when no Trash folder exists). */
    public function delete(string $folder, int $uid): void;
}
