<?php

namespace ApiGoat\OAuth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates an OAuth2 RS256 bearer request into an Authy session, reusing the
 * exact pattern McpEndpoint established. It ONLY establishes identity — it makes no
 * authorization decision and sets no rbac_* request attributes. Downstream
 * RbacMiddleware + AuthyMiddleware + Api::authorize + setAclFilter remain the
 * authorization boundary (identical to a browser session of the same identity).
 *
 * Performance: after a successful full authentication the hydrated AuthySession is
 * serialized into MicroCache keyed by sha256(token). Repeat calls within the TTL
 * restore it directly, skipping RS256 validation, the revocation SELECT, the authy
 * findPk, group loads, and the per-call UPDATE authy (last_login stamp).
 *
 * Knob: GC_BEARER_CACHE_TTL (seconds, default 60). Set to 0 to restore per-call
 * full authentication (revocation and deactivation are then instant). The raw token
 * is never stored — only its sha256 hash.
 */
final class BearerSessionAuthenticator
{
    public const AUTHENTICATED = 'authenticated';
    public const NOT_OAUTH     = 'not_oauth';     // not a valid OAuth2 token / OAuth not configured
    public const UNKNOWN_USER  = 'unknown_user';  // valid token but no Authy row
    public const INACTIVE      = 'inactive';      // valid token but account deactivated

    /**
     * @return self::AUTHENTICATED|self::NOT_OAUTH|self::UNKNOWN_USER|self::INACTIVE
     */
    public static function authenticate(ServerRequestInterface $request): string
    {
        $token = self::rawBearerToken($request);
        $ttl   = self::cacheTtl();
        $key   = $token !== '' ? 'gc:bearer:' . \hash('sha256', $token) : '';

        // Fast path: a token we fully authenticated within the TTL restores the
        // hydrated session with zero OAuth/DB work. Bounded staleness: revocation
        // or deactivation takes effect within <= TTL; GC_BEARER_CACHE_TTL=0
        // restores per-call checks. The raw token is never stored, only its hash.
        if ($key !== '' && $ttl > 0) {
            $blob = \ApiGoat\Utility\MicroCache::get($key);
            if (\is_string($blob) && $blob !== '') {
                // allowed_classes: defense-in-depth — the blob is server-produced
                // (stored below after a full authentication), and AuthySession
                // carries only scalars/arrays, so nothing else may instantiate.
                $restored = @\unserialize($blob, ['allowed_classes' => [\ApiGoat\Sessions\AuthySession::class]]);
                if ($restored instanceof \ApiGoat\Sessions\AuthySession
                    && $restored->get('connected') === 'YES') {
                    $_SESSION[\_AUTH_VAR] = $restored;
                    return self::AUTHENTICATED;
                }
                \ApiGoat\Utility\MicroCache::forget($key); // corrupt / stale entry
            }
        }

        $factory = OAuthServerFactory::forProject();
        if ($factory === null) {
            return self::NOT_OAUTH; // OAuth not configured on this project
        }

        try {
            $validated = $factory->resourceServer()->validateAuthenticatedRequest($request);
        } catch (\Throwable $e) {
            // Not a valid OAuth2 RS256 token (incl. HS256 tokens) — caller falls through.
            return self::NOT_OAUTH;
        }

        $authyId = (int) $validated->getAttribute('oauth_user_id');
        if ($authyId <= 0) {
            return self::UNKNOWN_USER;
        }

        // Already connected (idempotent) — nothing to do.
        if (isset($_SESSION[\_AUTH_VAR]) && $_SESSION[\_AUTH_VAR]->get('connected') === 'YES') {
            return self::AUTHENTICATED;
        }

        $authy = \App\AuthyQuery::create()->findPk($authyId);
        if (!$authy) {
            return self::UNKNOWN_USER;
        }
        if (!self::isAccountActive($authy)) {
            return self::INACTIVE;
        }

        try {
            // Bypass the AuthyService constructor (web-only helpers) exactly as
            // McpEndpoint / OAuthAuthorizeService do — setSession needs none of it.
            $svc = (new \ReflectionClass(\App\AuthyService::class))->newInstanceWithoutConstructor();
            $svc->setSession($authy);
        } catch (\Throwable $e) {
            error_log('[BearerSessionAuthenticator] setSession failed: ' . $e->getMessage());
        }

        $ok = isset($_SESSION[\_AUTH_VAR]) && $_SESSION[\_AUTH_VAR]->get('connected') === 'YES';

        // Store the hydrated session for subsequent calls with this token.
        if ($ok && $key !== '' && $ttl > 0) {
            \ApiGoat\Utility\MicroCache::put($key, $ttl, \serialize($_SESSION[\_AUTH_VAR]));
        }

        return $ok ? self::AUTHENTICATED : self::UNKNOWN_USER;
    }

    private static function rawBearerToken(ServerRequestInterface $request): string
    {
        $h = $request->getHeaderLine('Authorization');
        return (\stripos($h, 'Bearer ') === 0) ? \trim(\substr($h, 7)) : '';
    }

    /** GC_BEARER_CACHE_TTL seconds; default 60; 0 disables. */
    private static function cacheTtl(): int
    {
        $v = \function_exists('env') ? env('GC_BEARER_CACHE_TTL') : \getenv('GC_BEARER_CACHE_TTL');
        return ($v === false || $v === null || $v === '') ? 60 : \max(0, (int) $v);
    }

    /** Active unless getDeactivate() is a truthy non-'No' value. Objects without the method are active. */
    public static function isAccountActive($authy): bool
    {
        if (!\is_object($authy) || !\method_exists($authy, 'getDeactivate')) {
            return true;
        }
        $d = $authy->getDeactivate();
        return $d === null || $d === '' || $d === '0' || $d === 0 || \strcasecmp((string) $d, 'No') === 0;
    }
}
