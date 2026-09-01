<?php

namespace ApiGoat\Google;

use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * One place that turns a non-2xx Google API response into the runtime's
 * retry taxonomy (the queue already knows how to treat each):
 *
 *   401 / 403            → AuthFailed   (unless the 403 reason is a quota/rate limit → RateLimited)
 *   429                  → RateLimited  (exception code = Retry-After seconds, 0 when absent)
 *   404                  → TransientError code 404 (callers that need "not found" semantics check the code)
 *   5xx / anything else  → TransientError code = HTTP status
 */
final class ErrorMapper
{
    public const RATE_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'];

    public static function fail(string $context, int $status, string $rawHeaders, string $rawBody): never
    {
        $data = json_decode($rawBody, true);
        $data = is_array($data) ? $data : [];
        $msg  = (string) ($data['error']['message'] ?? ($rawBody !== '' ? mb_substr($rawBody, 0, 300) : "HTTP {$status}"));

        if ($status === 401 || $status === 403) {
            $reason = (string) ($data['error']['errors'][0]['reason'] ?? '');
            if ($reason === '') {
                foreach ($data['error']['details'] ?? [] as $d) {
                    $reason = (string) ($d['reason'] ?? '');
                    if ($reason !== '') break;
                }
            }
            if (in_array($reason, self::RATE_REASONS, true)) {
                throw new RateLimited("{$context}: {$msg}", self::retryAfter($rawHeaders));
            }
            throw new AuthFailed("{$context}: {$msg}", $status);
        }
        if ($status === 429) {
            throw new RateLimited("{$context}: {$msg}", self::retryAfter($rawHeaders));
        }
        if ($status === 404) {
            throw new TransientError("{$context}: not found", 404);
        }
        if ($status >= 500) {
            throw new TransientError("{$context} server error: {$msg}", $status);
        }
        throw new TransientError("{$context} returned HTTP {$status}: {$msg}", $status);
    }

    public static function retryAfter(string $rawHeaders): int
    {
        return preg_match('/^Retry-After:\s*(\d+)/im', $rawHeaders, $m) ? (int) $m[1] : 0;
    }
}
