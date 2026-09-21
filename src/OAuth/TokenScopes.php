<?php

namespace ApiGoat\OAuth;

/**
 * The OAuth scopes granted to the bearer token of the CURRENT request.
 *
 * The scopes were issued, stored and shown on the consent page, but read
 * nowhere: a token granted `crm:read` alone could create, update and delete.
 * BearerSessionAuthenticator records them here on every authentication; the
 * two bearer entry points consult readOnly() — ToolRegistry::granted() (MCP)
 * and OAuthResourceMiddleware (REST).
 *
 * Deliberately NOT kept on the AuthySession: that object is serialized into
 * the bearer cache and may be a cookie session the token merely matched, so a
 * scope stored there would outlive the request it belongs to.
 *
 * Enforcement is conservative, because live clients must keep working:
 *   - a token with `crm:read` and WITHOUT `crm:write` is read-only;
 *   - anything else is unrestricted, exactly as before — no scopes at all
 *     (clients that request none), pre-`crm:` tokens ("read write"), a token
 *     carrying only `offline_access`, and every non-bearer request.
 * Every first-party client (mobile template, Claude connectors registered
 * through DCR) requests `crm:read crm:write offline_access`.
 */
final class TokenScopes
{
    public const READ  = 'crm:read';
    public const WRITE = 'crm:write';

    /** @var list<string>|null NULL = this request carries no authenticated OAuth bearer */
    private static ?array $granted = null;

    /** @param mixed $scopes list of identifiers (strings or ScopeEntity-like), or NULL to clear */
    public static function set($scopes): void
    {
        if (!\is_array($scopes)) {
            self::$granted = null;
            return;
        }
        $out = [];
        foreach ($scopes as $s) {
            if (\is_object($s) && \method_exists($s, 'getIdentifier')) {
                $s = $s->getIdentifier();
            }
            if (\is_string($s) && $s !== '') {
                $out[] = $s;
            }
        }
        self::$granted = \array_values(\array_unique($out));
    }

    /** @return list<string>|null */
    public static function granted(): ?array
    {
        return self::$granted;
    }

    /** True only for a token that was granted read and was NOT granted write. */
    public static function readOnly(): bool
    {
        return self::$granted !== null
            && \in_array(self::READ, self::$granted, true)
            && !\in_array(self::WRITE, self::$granted, true);
    }

    /**
     * Scopes claim of a league access token (JWT payload `scopes`), WITHOUT
     * verifying it. Only for a token already proven authentic — the bearer
     * cache hit, where sha256(token) matched a token that passed full RS256
     * validation moments ago. Unreadable payload → [] (unrestricted legacy
     * shape), never a guess.
     *
     * @return list<string>
     */
    public static function fromJwt(string $token): array
    {
        $parts = \explode('.', $token);
        if (\count($parts) !== 3) {
            return [];
        }
        $json = \base64_decode(\strtr($parts[1], '-_', '+/'), false);
        $claims = \is_string($json) ? \json_decode($json, true) : null;
        $scopes = \is_array($claims) ? ($claims['scopes'] ?? []) : [];
        return \is_array($scopes) ? \array_values(\array_filter($scopes, '\is_string')) : [];
    }
}
