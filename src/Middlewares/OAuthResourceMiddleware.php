<?php

declare(strict_types=1);

namespace ApiGoat\Middlewares;

use ApiGoat\OAuth\BearerSessionAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Authenticates OAuth2 RS256 bearer tokens into an Authy session for REST API routes,
 * reusing BearerSessionAuthenticator (the same pattern as McpEndpoint).
 *
 * It ONLY establishes identity. It sets NO rbac_complete/rbac_public attributes and
 * enforces NO scopes. A bearer request is then authorized IDENTICALLY to a browser
 * session of the same identity: RbacMiddleware (api_rbac) + AuthyMiddleware +
 * Api::authorize (per-op r/a/w/d) + setAclFilter (Owner/Group/tenant) all apply.
 *
 * Non-OAuth (HS256) tokens are not matched and fall through to JwtAuthentication.
 */
class OAuthResourceMiddleware implements MiddlewareInterface
{
    /** Framework legacy (is_api=false) actions that need bearer identity: file upload/download + mass actions. */
    private const LEGACY_BEARER_ACTIONS = ['upload', 'open', 'file', 'mass'];

    /**
     * The legacy actions a bearer client may authenticate to: the framework's own,
     * plus whatever the project declares in settings under `bearer_legacy_actions`.
     *
     * Projects mount their own non-API routes (apigoatacc: Cost/scanReceipt and
     * Client/scanAndCreateClient — the dashboard's receipt / business-card scans).
     * A mobile bearer client could not reach ANY of them: identity was only
     * hydrated for the four hardcoded names, so the request arrived
     * unauthenticated. A shared runtime can't grow a per-project const, hence the
     * settings hook. Widening it grants IDENTITY only — api_rbac, Api::authorize
     * and the ACL still gate the request exactly as they do for a browser session.
     *
     * @param array<string,mixed> $settings
     * @return list<string>
     */
    public static function legacyBearerActions(array $settings): array
    {
        $extra = $settings['bearer_legacy_actions'] ?? null;
        if (!is_array($extra)) {
            return self::LEGACY_BEARER_ACTIONS;
        }
        $out = self::LEGACY_BEARER_ACTIONS;
        foreach ($extra as $action) {
            // Never admit an empty/blank entry: it would match a request carrying
            // no action at all and hydrate identity on routes nobody listed.
            if (!is_string($action) || trim($action) === '' || in_array($action, $out, true)) {
                continue;
            }
            $out[] = $action;
        }
        return $out;
    }

    /**
     * Pure routing predicate (unit-tested). Bearer identity is hydrated for API routes
     * AND for legacy is_api=false actions (see legacyBearerActions) — those are dispatched
     * only by the legacy route, so without this the mobile bearer client cannot reach them.
     * Hydration is identity-only; RbacMiddleware (api_rbac) + Authy +
     * Api::authorize + ACL still gate the request identically to a browser session.
     */
    public static function shouldAttempt(bool $isApi, bool $alreadyConnected, bool $hasBearer, bool $isLegacyBearerAction = false): bool
    {
        return ($isApi || $isLegacyBearerAction) && !$alreadyConnected && $hasBearer;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsed = $request->getAttribute('parsed_args');
        $isApi = is_array($parsed) && (($parsed['is_api'] ?? false) == true);
        $action = is_array($parsed) ? (string) ($parsed['action'] ?? '') : '';
        $isLegacyBearerAction = in_array($action, self::legacyBearerActions(\ApiGoat\Utility\Settings::load()), true);

        $connected = isset($_SESSION[\_AUTH_VAR]) && $_SESSION[\_AUTH_VAR]->get('connected') === 'YES';

        $authLine = $request->getHeaderLine('Authorization');
        if ($authLine === '') {
            $authLine = $request->getHeaderLine('X-Authorization');
        }
        $hasBearer = (bool) preg_match('/Bearer\s+\S/i', $authLine);

        if (!self::shouldAttempt($isApi, $connected, $hasBearer, $isLegacyBearerAction)) {
            return $handler->handle($request);
        }

        $status = BearerSessionAuthenticator::authenticate($request);

        if ($status === BearerSessionAuthenticator::AUTHENTICATED
            || $status === BearerSessionAuthenticator::NOT_OAUTH) {
            // Authenticated → continue with hydrated session (RBAC/Authy/Api authorize downstream).
            // NOT_OAUTH → let JwtAuthentication handle/reject the token.
            return $handler->handle($request);
        }

        // Valid OAuth token but no usable/active identity → 401.
        $response = new Response();
        $response->getBody()->write(json_encode(
            ['status' => 'failure', 'errors' => ['unauthorized']],
            JSON_UNESCAPED_SLASHES
        ));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}
