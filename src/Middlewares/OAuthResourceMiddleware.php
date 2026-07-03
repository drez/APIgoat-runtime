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
    /** File-upload/download actions served by the legacy (is_api=false) {Model}/{action} route. */
    private const FILE_ACTIONS = ['upload', 'open', 'file'];

    /**
     * Pure routing predicate (unit-tested). Bearer identity is hydrated for API routes
     * AND for the file-upload/download actions (upload/open/file) — those are dispatched
     * only by the legacy is_api=false route, so without this the mobile bearer client
     * cannot reach them. Hydration is identity-only; RbacMiddleware (api_rbac) + Authy +
     * Api::authorize + ACL still gate the request identically to a browser session.
     */
    public static function shouldAttempt(bool $isApi, bool $alreadyConnected, bool $hasBearer, bool $isFileAction = false): bool
    {
        return ($isApi || $isFileAction) && !$alreadyConnected && $hasBearer;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsed = $request->getAttribute('parsed_args');
        $isApi = is_array($parsed) && (($parsed['is_api'] ?? false) == true);
        $action = is_array($parsed) ? (string) ($parsed['action'] ?? '') : '';
        $isFileAction = in_array($action, self::FILE_ACTIONS, true);

        $connected = isset($_SESSION[\_AUTH_VAR]) && $_SESSION[\_AUTH_VAR]->get('connected') === 'YES';

        $authLine = $request->getHeaderLine('Authorization');
        if ($authLine === '') {
            $authLine = $request->getHeaderLine('X-Authorization');
        }
        $hasBearer = (bool) preg_match('/Bearer\s+\S/i', $authLine);

        if (!self::shouldAttempt($isApi, $connected, $hasBearer, $isFileAction)) {
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
