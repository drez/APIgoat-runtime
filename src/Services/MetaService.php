<?php

namespace ApiGoat\Services;

use ApiGoat\Api\ApiResponse;

/**
 * GET /api/v1/_meta — RBAC-filtered entity/field catalog for the calling user.
 *
 * Thin envelope over ApiGoat\Api\MetaCatalog. JWT-required (not on the jwt
 * ignore list): the session must already be rights-hydrated so the catalog can
 * filter to what this user may touch.
 */
class MetaService extends Service
{
    public function getApiResponse()
    {
        $body = [
            'status'   => 'success',
            'data'     => $this->buildCatalog(),
            'errors'   => [],
            'messages' => null,
        ];

        // ApiResponse derives the 200 from method=GET in $this->args
        // (matching the AccountService pattern: args, not request).
        $ApiResponse = new ApiResponse($this->args, $this->response, $body);
        return $ApiResponse->getResponse();
    }

    /** Overridable seam (unit-tested in isolation). */
    protected function buildCatalog(): array
    {
        $routes = require _BASE_DIR . 'config/Built/settings.routes.php';
        $entityNames = array_keys($routes['json']['GET'] ?? []);

        $session = $_SESSION[\_AUTH_VAR];
        $catalog = (new \ApiGoat\Api\MetaCatalog($session))->build($entityNames);

        // Screen spec (may be absent on projects built before ScreenSpec landed).
        $screensFile = _BASE_DIR . 'config/Built/screens.php';
        $screens = is_file($screensFile) ? (require $screensFile) : [];
        $catalog['screens'] = \ApiGoat\Services\MetaFilter::filterScreens($screens, $session);

        // Menu (already emitted for the web admin). Hide entries for entities the
        // user cannot read; keep structural groups. We only hide a menu entry
        // whose name is a known catalog entity that got filtered out of `entities`.
        $menuFile = _BASE_DIR . 'config/Built/settings.menus.php';
        $menu = is_file($menuFile) ? (require $menuFile) : [];
        $visibleEntities = array_keys($catalog['entities'] ?? []);
        $catalog['menu'] = $this->filterMenuAgainstEntities($menu, $entityNames, $visibleEntities);

        $catalog['theme'] = self::resolveTheme($session, self::allowedThemes());

        return $catalog;
    }

    /** Resolve the signed-in user's theme name (validated), defaulting to 'mint'. */
    public static function resolveTheme($sess, array $allowed): string
    {
        if (!is_object($sess)) { return 'mint'; }
        $cached = isset($sess->sessVar['Theme']) ? $sess->sessVar['Theme'] : null;
        if (is_string($cached) && in_array($cached, $allowed, true)) { return $cached; }
        $userId = (int) ($sess->get('id') ?? 0);
        if ($userId && class_exists('\App\AuthyQuery')) {
            $authy = \App\AuthyQuery::create()->findPk($userId);
            if ($authy && method_exists($authy, 'getTheme')) {
                $t = (string) $authy->getTheme();
                if (in_array($t, $allowed, true)) { return $t; }
            }
        }
        return 'mint';
    }

    /** Valid theme names — from the authy.theme ENUM valueSet when present, else the base five. */
    private static function allowedThemes(): array
    {
        if (class_exists('\App\AuthyPeer') && defined('\App\AuthyPeer::THEME')) {
            $vs = \App\AuthyPeer::getValueSet(\App\AuthyPeer::THEME);
            if (is_array($vs) && !empty($vs)) { return array_values($vs); }
        }
        return ['mint', 'ink', 'indigo', 'terracotta', 'graphite'];
    }

    /**
     * Drop a menu entry only when its `name` is a known entity (present in the
     * full route entity list) that is NOT in the user-visible entity set.
     * Structural/group entries (names not in the entity list) are always kept.
     */
    private function filterMenuAgainstEntities(array $menu, array $allEntities, array $visibleEntities): array
    {
        $allSet = array_flip($allEntities);
        $visibleSet = array_flip($visibleEntities);
        $out = [];
        foreach ($menu as $entry) {
            $name = $entry['name'] ?? '';
            if (isset($allSet[$name]) && !isset($visibleSet[$name])) {
                continue; // known entity the user can't read
            }
            $out[] = $entry;
        }
        return $out;
    }
}
