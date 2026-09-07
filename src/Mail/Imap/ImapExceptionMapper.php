<?php

namespace ApiGoat\Mail\Imap;

use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;

/**
 * Library / socket exceptions → the runtime taxonomy. Decides on the
 * exception's SHORT class name and message text so it works for
 * webklex/php-imap's exception set without depending on it:
 *
 *   *AuthFailed*, "authentication failed", "invalid credentials", "LOGIN failed"  → AuthFailed
 *   "too many", "throttl", "rate limit", "try again later", "[OVERQUOTA]"          → RateLimited
 *   the MESSAGE itself is gone ("no headers found", "message not found",
 *     "uid N not found", "[NONEXISTENT]", a *MessageNotFound* class)               → ValidationRejected (permanent)
 *   everything else (connection, timeout, response, runtime, a missing folder)     → TransientError
 *
 * ORDER IS THE GUARD, not the wording. ImapConnector::guard() wraps EVERY
 * operation — connect, folder list, status, uids, move, delete — so a
 * server's auth refusal reaches this mapper too, and the common wording for
 * a rotated password is `NO [AUTHENTICATIONFAILED] User does not exist`.
 * With the gone-check first, that became ValidationRejected, which
 * JobQueue::isPermanent() treats as permanent: a mailbox needing re-auth
 * would be failed forever instead of flagged. So AuthFailed and RateLimited
 * are decided FIRST, and the gone patterns are anchored to a message scope
 * ("message …", "uid N …") — never a bare "does not exist", which is also
 * what a server says about a folder that was renamed.
 */
final class ImapExceptionMapper
{
    public static function map(\Throwable $e, string $context = 'IMAP'): \RuntimeException
    {
        // Already ours — pass through untouched.
        if ($e instanceof AuthFailed || $e instanceof RateLimited || $e instanceof TransientError || $e instanceof ValidationRejected) {
            return $e;
        }
        $short = (string) substr(strrchr('\\' . get_class($e), '\\'), 1);
        $msg   = $e->getMessage();
        $lc    = strtolower($msg);
        $text  = "{$context}: " . ($msg !== '' ? $msg : $short);

        // Auth first: guard() wraps every operation, so an auth refusal that
        // happens to contain "does not exist" must not be read as a gone
        // message and permanently failed. See the class comment.
        if (stripos($short, 'AuthFailed') !== false || stripos($short, 'Authentication') !== false
            || preg_match('/authenticat\w* failed|invalid credentials|login failed|\[authenticationfailed\]|authorization failed|not authenticated/', $lc)) {
            return new AuthFailed($text, 0, $e);
        }
        if (preg_match('/too many|throttl|rate ?limit|try again later|\[overquota\]|\[limit\]|temporarily (?:blocked|unavailable)/', $lc)) {
            return new RateLimited($text, 0, $e);
        }
        // The MESSAGE itself is gone (deleted/archived/expunged server-side):
        // permanent, never worth retrying. Every pattern is scoped to a
        // message — a bare "does not exist" is also how a server answers about
        // a renamed FOLDER, and that is a config problem a human fixes, so it
        // falls through to TransientError and the mailbox keeps retrying.
        if (stripos($short, 'MessageNotFound') !== false
            || preg_match('/no headers found|message (?:does not exist|not found)|uid \d+ (?:does not exist|not found)|\[nonexistent\]/i', $lc)) {
            return new ValidationRejected($text, 0, $e);
        }
        return new TransientError($text, 0, $e);
    }
}
