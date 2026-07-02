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

        return $catalog;
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
