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
     * Pass requested scopes through unchanged (CRM RBAC enforces per call, not at
     * scope level). Matches league 8.5 interface — no trailing $authCodeId param
     * (that was added in league 9).
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        return $scopes;
    }
}
