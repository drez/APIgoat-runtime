<?php

namespace ApiGoat\Mail;

/**
 * What a client-side drafts mirror and a send path need from a mailbox:
 * write a draft version, delete ONE of those versions again, and look a
 * message up by its Message-ID (the never-send-twice recheck in Sent).
 *
 * Separate from MailConnector / FolderWriter like StateWriter: the caller
 * checks `instanceof DraftStore`.
 *
 * deleteDraft() is the only hard delete in the mail runtime. It refuses,
 * before any server call, an id that is not in $draftsFolder (exact path
 * match) or is "unresolved:", and refuses without UIDPLUS: without UID
 * EXPUNGE the only way to remove a message is a folder-wide EXPUNGE, which
 * would take every \Deleted message of the folder with it.
 */
interface DraftStore
{
    /** APPEND flagged \Seen \Draft. @return string the new provider id, '' when the server did not report it (no APPENDUID) */
    public function appendDraft(string $draftsFolder, string $raw): string;

    /**
     * Delete exactly $providerId, which must live in $draftsFolder AND still
     * carry $expectedMessageId (with or without <>); otherwise nothing is
     * deleted. Throws:
     *  - TransientError 404: the uid is already gone (like setSeen);
     *  - ValidationRejected 409: the uid holds another message (UIDVALIDITY
     *    change, another client's draft) or the folder opened read-only;
     *  - UnsupportedOperation: no UIDPLUS (the caller logs it);
     *  - InvalidArgumentException: refused before any server call.
     */
    public function deleteDraft(string $providerId, string $draftsFolder, string $expectedMessageId): void;

    /** The provider id of the newest message in $folder whose Message-ID header is $messageId (with or without <>), or null. */
    public function findByMessageId(string $folder, string $messageId): ?string;
}
