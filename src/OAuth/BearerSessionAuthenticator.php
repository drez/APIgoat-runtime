<?php

namespace ApiGoat\OAuth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticates an OAuth2 RS256 bearer request into an Authy session, reusing the
 * exact pattern McpEndpoint established. It ONLY establishes identity — it makes no
 * authorization decision and sets no rbac_* request attributes. Downstream
 * RbacMiddleware + AuthyMiddleware + Api::authorize + setAclFilter remain the
 * authorization boundary (identical to a browser session of the same identity).
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

        return (isset($_SESSION[\_AUTH_VAR]) && $_SESSION[\_AUTH_VAR]->get('connected') === 'YES')
            ? self::AUTHENTICATED
            : self::UNKNOWN_USER;
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
