<?php

namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    private const KNOWN = ['crm:read', 'crm:write', 'offline_access'];

    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        if (!in_array($identifier, self::KNOWN, true)) {
            return null;
        }
        $s = new ScopeEntity();
        $s->setIdentifier($identifier);
        return $s;
    }

    /**
     * The scopes a token is issued with (authorization-code grant; a refresh
     * keeps the original scopes or fewer):
     *  - unknown identifiers are dropped (league already rejects them in
     *    validateScopes — belt and braces);
     *  - a scope the client did not register (oauth_client.scopes) is dropped,
     *    so a client registered narrow can't ask for more;
     *  - a request naming neither crm:read nor crm:write gets the client's
     *    registered crm:* scopes (DEFAULT when none are registered) — a client
     *    that asks for no scope keeps working, while TokenScopes now denies a
     *    token without them (default-deny).
     *
     * Every first-party client (mobile seed, DCR default, Claude connectors)
     * registers and requests "crm:read crm:write offline_access".
     * Matches league 8.5 interface — no trailing $authCodeId param.
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        $registered = method_exists($clientEntity, 'getRegisteredScopes') ? $clientEntity->getRegisteredScopes() : null;
        $ids = [];
        foreach ($scopes as $s) {
            $ids[] = \is_object($s) && \method_exists($s, 'getIdentifier') ? (string) $s->getIdentifier() : (string) $s;
        }
        $out = [];
        foreach (self::finalIdentifiers($ids, $registered) as $id) {
            $out[] = $this->getScopeEntityByIdentifier($id);
        }
        return $out;
    }

    /** Scopes granted when the client has none registered (legacy rows). */
    public const DEFAULT = ['crm:read', 'crm:write'];

    /**
     * Pure core of finalizeScopes().
     *
     * @param list<string>      $requested
     * @param list<string>|null $registered oauth_client.scopes; null/empty = legacy row
     * @return list<string>
     */
    public static function finalIdentifiers(array $requested, ?array $registered): array
    {
        $registered = array_values(array_intersect($registered ?? [], self::KNOWN));
        $out = array_values(array_unique(array_intersect($requested, self::KNOWN)));
        if ($registered !== []) {
            $out = array_values(array_intersect($out, $registered));
        }
        if (!in_array('crm:read', $out, true) && !in_array('crm:write', $out, true)) {
            $grant = $registered !== []
                ? array_values(array_intersect($registered, ['crm:read', 'crm:write']))
                : self::DEFAULT;
            $out = array_values(array_unique(array_merge($out, $grant)));
        }
        return $out;
    }
}
