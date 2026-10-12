<?php

namespace ApiGoat\Mail;

/**
 * A connector that can say, cheaply, how much mail a folder holds from a
 * given date on — what an onboarding progress bar needs to size an import
 * window before (and while) it is walked with {@see MailConnector::fetchBefore()}.
 *
 * Separate from {@see MailConnector} on purpose (precedent: {@see FolderLister}):
 * test doubles and connectors that cannot answer are not forced to. Callers
 * must check `instanceof HistoryCounter`.
 */
interface HistoryCounter
{
    /**
     * Messages in $folder ('' = the connector's configured folder) received
     * on/after $since. Approximate is fine (IMAP = day granularity, Gmail =
     * resultSizeEstimate). A $since at or before the Unix epoch means the
     * whole folder.
     *
     * Only IMAP maps every failure to null; Gmail and Graph may throw
     * AuthFailed / RateLimited / TransientError. Callers must treat ANY
     * throwable as "unknown".
     *
     * @return int|null null = the provider cannot say cheaply; the caller
     *                  shows an indeterminate bar
     */
    public function countSince(string $folder, \DateTimeImmutable $since): ?int;
}
