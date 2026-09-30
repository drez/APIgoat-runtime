<?php

namespace ApiGoat\Mail;

/**
 * What the SERVER says about one message right now — the third side of the
 * base / desired / server compare a state mirror makes before writing.
 *
 *  - seen, flagged  \Seen / \Flagged (Gmail: no UNREAD / STARRED)
 *  - folder         IMAP: the folder the provider id points into. Gmail: the
 *                   system label that places it (INBOX, SPAM, TRASH, SENT,
 *                   DRAFT), '' when archived
 *  - role           Gmail only: Inbox, Archive, Trash, Spam, Sent or Drafts,
 *                   derived from the labels. Null on IMAP, where only the
 *                   caller's folder registry knows what a path is for
 */
final class MessageState
{
    /** @param string[] $labels raw flags (IMAP) or label ids (Gmail) */
    public function __construct(
        public readonly bool $seen,
        public readonly bool $flagged,
        public readonly string $folder,
        public readonly ?string $role = null,
        public readonly array $labels = [],
    ) {
    }
}
