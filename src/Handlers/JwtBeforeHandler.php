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
 * References project-level \App\AuthyQuery / \App\AuthyService, in
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
        // SECURITY (review R1): never hydrate a session for a deactivated or
        // expired account on the token path. Password login filters these out
        // (AuthyClass deactivate filter), but the JWT/refresh path loaded the
        // row with a bare findPk — so a disabled/compromised account kept API
        // access for the refresh-token-family window. Mirror login's
        // "active = deactivate null/'No'/0" semantics; expiry is also enforced
        // in setSession, re-checked here for defense in depth.
        if (method_exists($authyRow, 'getDeactivate')) {
            $deact  = $authyRow->getDeactivate();
            $active = ($deact === null || $deact === '' || $deact === '0' || $deact === 0
                || strcasecmp((string) $deact, 'No') === 0);
            if (!$active) {
                error_log('[JwtAuthentication before] refused: account deactivated');
                return;
            }
        }
        if (method_exists($authyRow, 'getExpire')) {
            $exp = $authyRow->getExpire();
            if ($exp !== null && $exp !== '' && ($ts = strtotime((string) $exp)) !== false && $ts <= time()) {
                error_log('[JwtAuthentication before] refused: account expired');
                return;
            }
        }
        // \App\AuthyServiceWrapper is emitted as a class-less stub (build copy skips it), so the
        // class never loads. Use the real \App\AuthyService, which carries setSession().
        $AuthyService = new \App\AuthyService($request, null, $routeArgs);
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
