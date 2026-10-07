<?php

namespace ApiGoat\Microsoft;

use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Turns a non-2xx Microsoft Graph response into the runtime's retry taxonomy:
 *
 *   401 / 403   → AuthFailed   (message carries Graph error.code)
 *   429 / 503   → RateLimited  (code = Retry-After seconds, default 30)
 *   404 / 410   → TransientError with that status as code
 *   other 5xx / anything else → TransientError code = HTTP status
 */
final class GraphErrorMapper
{
    public const DEFAULT_RETRY_AFTER = 30;

    public static function fail(string $context, int $status, string $rawHeaders, string $rawBody): never
    {
        $data = json_decode($rawBody, true);
        $data = is_array($data) ? $data : [];
        $code = (string) ($data['error']['code'] ?? '');
        $msg  = (string) ($data['error']['message'] ?? ($rawBody !== '' ? mb_substr($rawBody, 0, 300) : "HTTP {$status}"));
        $text = "{$context}: " . ($code !== '' ? "{$code}: " : '') . $msg;

        if ($status === 401 || $status === 403) {
            throw new AuthFailed($text, $status);
        }
        if ($status === 429 || $status === 503) {
            throw new RateLimited($text, self::retryAfter($rawHeaders), null, $status);
        }
        if ($status === 404 || $status === 410) {
            throw new TransientError($text, $status);
        }
        throw new TransientError(($status >= 500 ? "{$context} server error: " : "{$context} returned HTTP {$status}: ") . ($code !== '' ? "{$code}: " : '') . $msg, $status);
    }

    public static function retryAfter(string $rawHeaders): int
    {
        return preg_match('/^Retry-After:\s*(\d+)/im', $rawHeaders, $m) ? (int) $m[1] : self::DEFAULT_RETRY_AFTER;
    }
}
