<?php

namespace ApiGoat\Mail;

/**
 * A connector that can write INTO a mailbox, not just read it and shuffle
 * what is already there — the primitives a caller needs to copy mail
 * between two servers (fetchRaw() on one, append() on the other) and to
 * file it under a folder that may not exist yet (ensureFolder()).
 *
 * Separate from {@see MailConnector} on purpose, like {@see FolderLister}:
 * move()/trash() stay inside one account, and most providers either cannot
 * APPEND arbitrary RFC 822 source (Gmail's API wants its own import call)
 * or should not be asked to create folders on a whim. Kept out of the
 * MailConnector contract so implementations that cannot do it (and test
 * doubles) are not forced to.
 *
 * Folder names are the caller's words, not the server's: "INBOX/SPAM" and
 * "inbox.spam" name the same folder whichever hierarchy delimiter the
 * server happens to use. ensureFolder() resolves them to the server's real
 * path, which is what append() and move() then want.
 */
interface FolderWriter
{
    /** The server's real path of $wanted, creating it when missing. Matches existing folders case-insensitively under either hierarchy delimiter ("INBOX/SPAM" == "INBOX.SPAM"). */
    public function ensureFolder(string $wanted): string;

    /** The full RFC 822 source of a message. */
    public function fetchRaw(string $providerId): string;

    /** APPEND $raw to $folder (flag \Seen when $seen). Returns the new provider id, or '' when the server does not report the new UID. */
    public function append(string $folder, string $raw, bool $seen): string;
}
