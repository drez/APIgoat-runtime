<?php

namespace ApiGoat\Services;

/**
 * Pure RBAC filters for the /_meta menu + screens sections. Extracted so the
 * filtering is unit-testable without a live session/DB. The session arg only
 * needs isAdmin():bool and hasRights(string,string):mixed (AuthySession).
 *
 * Menu filtering (entity-aware) lives in MetaService::filterMenuAgainstEntities
 * so it can intersect menu names against the full route-entity list.
 */
final class MetaFilter
{
    /** Keep only entities the session may read (mirrors MetaCatalog::permitted). */
    public static function filterScreens(array $screens, $session): array
    {
        $out = [];
        foreach ($screens as $entity => $spec) {
            if (self::canRead($entity, $session)) {
                $out[$entity] = $spec;
            }
        }
        return $out;
    }

    private static function canRead(string $entity, $session): bool
    {
        return $session->isAdmin() || (bool) $session->hasRights($entity, 'r');
    }
}
