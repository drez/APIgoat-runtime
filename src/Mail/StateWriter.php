<?php

namespace ApiGoat\Mail;

/**
 * A connector that can report and change ONE message's state — what a
 * client-side outbox needs to mirror a user's read / flag / location change
 * and to notice that the server moved on without it.
 *
 * Separate from {@see MailConnector}, like {@see FolderLister} and
 * {@see FolderWriter}: test doubles and connectors that cannot do it are not
 * forced to. The caller checks `instanceof StateWriter`.
 *
 * Every method refuses an "unresolved:<id>" provider id (a row whose server
 * copy is unknown) with an \InvalidArgumentException before any server call.
 * No method ever hard-deletes, and an id this returns is never a guess: ''
 * means the server did not report the new id (IMAP without COPYUID).
 */
interface StateWriter
{
    /** The server's view of $providerId, or null when that id no longer exists (IMAP: moved or expunged; Gmail: deleted). */
    public function messageState(string $providerId): ?MessageState;

    public function setFlag(string $providerId, bool $flagged): void;

    /**
     * Out of the inbox. Gmail: drop INBOX and SPAM (untrash first when
     * trashed), the id never changes. IMAP: MOVE to $archiveFolder; when it
     * is '' the server's own \Archive (else \All) folder, and unsupported
     * when it declares neither. Gmail over IMAP: MOVE to All Mail (drops the
     * Inbox label); a message already in All Mail is left alone.
     *
     * @return string the provider id afterwards ('' = unknown)
     */
    public function archive(string $providerId, string $archiveFolder = ''): string;

    /**
     * Back into $toFolder ('' = INBOX / the configured folder) from Trash,
     * Spam or Archive. Gmail: the untrash endpoint when trashed, then add the
     * label and drop SPAM. IMAP: MOVE. Gmail over IMAP, out of All Mail:
     * COPY (adds the label; an expunge from All Mail may delete the message).
     *
     * @return string the provider id afterwards ('' = unknown)
     */
    public function untrash(string $providerId, string $toFolder = ''): string;
}
