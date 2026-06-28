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

        return (new \ApiGoat\Api\MetaCatalog($_SESSION[\_AUTH_VAR]))->build($entityNames);
    }
}
