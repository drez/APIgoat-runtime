<?php

namespace ApiGoat\Mail;

/**
 * A connector that can say, cheaply, which messages a folder holds — the
 * primitive a caller needs to notice mail that was deleted, archived or
 * moved server-side.
 *
 * Separate from {@see MailConnector} on purpose: fetchHeaders() and
 * fetchBefore() both pay per message (a header FETCH per IMAP UID, a
 * messages.get per Gmail id), so answering "what is still here" through them
 * costs minutes on a real mailbox. listIds() is one IMAP `UID SEARCH ALL`,
 * or one Gmail messages.list call per 500 ids — cheap enough to run on every
 * poll. Kept out of the MailConnector contract so implementations that
 * cannot do it (and test doubles) are not forced to.
 */
interface FolderLister
{
    /** Every provider id currently in $folder ('' = the connector's configured folder). */
    public function listIds(string $folder): FolderListing;
}
