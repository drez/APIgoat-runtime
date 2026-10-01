<?php

namespace ApiGoat\Mail\Imap;

/** The IMAP primitives behind {@see \ApiGoat\Mail\DraftStore}. Throws the runtime taxonomy like ImapTransport. */
interface ImapDraftTransport
{
    /** APPEND with these flags ("\\Seen", "\\Draft"). @return int APPENDUID's uid, 0 when not reported */
    public function appendWithFlags(string $folder, string $raw, array $flags): int;

    /** The server advertises UIDPLUS (RFC 4315): UID EXPUNGE exists. */
    public function hasUidPlus(): bool;

    /**
     * In one forced read-write SELECT of $folder: fetch <uid>'s Message-ID,
     * and only when it equals $expectedMessageId ("<…>") UID STORE <uid>
     * +FLAGS.SILENT (\Deleted), then UID EXPUNGE <uid>. Never a plain EXPUNGE.
     * TransientError 404 when the uid is not in $folder; ValidationRejected
     * 409 (nothing written) on another Message-ID or a read-only SELECT.
     */
    public function expungeUid(string $folder, int $uid, string $expectedMessageId): void;

    /** UID SEARCH HEADER <header> "<value>". @return int[] ascending */
    public function searchHeader(string $folder, string $header, string $value): array;
}
