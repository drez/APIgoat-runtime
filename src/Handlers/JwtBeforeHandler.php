<?php

declare(strict_types=1);

namespace ApiGoat\Handlers;

use JimTools\JwtAuth\Handlers\BeforeHandlerInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * JWT BeforeHandler invoked by JimTools JwtAuthentication after a token decodes.
 *
 * Hydrates the AuthySession from the matched Authy row. Fail-closed: if
 * setSession refuses (expired user, corrupted rights JSON), connected
 * stays 'NO' and AuthyMiddleware returns 401 downstream.
 *
 * References project-level \App\AuthyQuery / \App\AuthyServiceWrapper, in
 * line with the rest of the runtime (e.g. Routes\RouteHelper).
 */
class JwtBeforeHandler implements BeforeHandlerInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    private function hydrateFromAuthyRow(Request $request, array $routeArgs, $authyRow): void
    {
        if (!$authyRow) {
            return;
        }
        $AuthyService = new \App\AuthyServiceWrapper($request, null, $routeArgs);
        try {
            $AuthyService->setSession($authyRow);
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[JwtAuthentication before] setSession failed: ' . $e->getMessage());
            }
        }
    }

    public function __invoke(Request $request, array $arguments): Request
    {
        // Deep-normalize claims (Firebase may leave nested stdClass; shallow cast breaks isset paths).
        $raw     = $arguments['decoded'] ?? [];
        $decoded = json_decode(json_encode($raw), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $this->container->set('token', $decoded);
        $request = $request->withAttribute('jwt_claims', $decoded);
        $routeArgs = ['decoded' => $decoded, 'token' => (string) ($arguments['token'] ?? '')];

        if ($_SESSION[_AUTH_VAR]->get('connected') === 'YES') {
            return $request;
        }

        if (!empty($decoded['authyId'])) {
            $authy = \App\AuthyQuery::create()->findPk((int) $decoded['authyId']);
            $this->hydrateFromAuthyRow($request, $routeArgs, $authy);

            return $request;
        }

        if (!empty($decoded['username'])) {
            $authy = \App\AuthyQuery::create()->filterByUsername((string) $decoded['username'])->findOne();
            $this->hydrateFromAuthyRow($request, $routeArgs, $authy);

            return $request;
        }

        if (!empty($decoded['user']) && (string) $decoded['user'] === 'web') {
            $webUser = \App\AuthyQuery::create()->filterByUsername('web')->findOne();
            $this->hydrateFromAuthyRow($request, $routeArgs, $webUser);
        }

        return $request;
    }
}
