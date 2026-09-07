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
 *   class/message says the message itself is gone ("no headers found",
 *     "message not found", "uid N not found", "[NONEXISTENT]",
 *     "does not exist", a *MessageNotFound* class)                            → ValidationRejected (permanent)
 *   *AuthFailed*, "authentication failed", "invalid credentials", "LOGIN failed"  → AuthFailed
 *   "too many", "throttl", "rate limit", "try again later", "[OVERQUOTA]"          → RateLimited
 *   everything else (connection, timeout, response, runtime)                        → TransientError
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

        // The message itself is gone (deleted/archived/expunged server-side):
        // permanent, never worth retrying. Checked before the AuthFailed
        // wording so it wins over the generic fallthrough, but never over
        // AuthFailed/RateLimited themselves — those stay their own thing.
        if (stripos($short, 'MessageNotFound') !== false
            || preg_match('/no headers found|message not found|uid \d+ not found|\[nonexistent\]|does not exist/i', $lc)) {
            return new ValidationRejected($text, 0, $e);
        }
        if (stripos($short, 'AuthFailed') !== false || stripos($short, 'Authentication') !== false
            || preg_match('/authenticat\w* failed|invalid credentials|login failed|\[authenticationfailed\]|authorization failed|not authenticated/', $lc)) {
            return new AuthFailed($text, 0, $e);
        }
        if (preg_match('/too many|throttl|rate ?limit|try again later|\[overquota\]|\[limit\]|temporarily (?:blocked|unavailable)/', $lc)) {
            return new RateLimited($text, 0, $e);
        }
        return new TransientError($text, 0, $e);
    }
}
