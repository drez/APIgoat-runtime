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
    /** Pure routing predicate (unit-tested). */
    public static function shouldAttempt(bool $isApi, bool $alreadyConnected, bool $hasBearer): bool
    {
        return $isApi && !$alreadyConnected && $hasBearer;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsed = $request->getAttribute('parsed_args');
        $isApi = is_array($parsed) && (($parsed['is_api'] ?? false) == true);

        $connected = isset($_SESSION[\_AUTH_VAR]) && $_SESSION[\_AUTH_VAR]->get('connected') === 'YES';

        $authLine = $request->getHeaderLine('Authorization');
        if ($authLine === '') {
            $authLine = $request->getHeaderLine('X-Authorization');
        }
        $hasBearer = (bool) preg_match('/Bearer\s+\S/i', $authLine);

        if (!self::shouldAttempt($isApi, $connected, $hasBearer)) {
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
