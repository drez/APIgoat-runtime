<?php

/*
 * CORS for the GoatCheese runtime.
 *
 * Replaces tuupola/cors-middleware (which pulled in neomerx/cors-psr7). This is
 * a deliberately FIXED-POLICY middleware: a wildcard origin, a closed header
 * allow-list, and credentials FORCED OFF. It never reflects the request Origin
 * and never reads the `cors` settings block, so a project cannot accidentally
 * weaken it (e.g. reflect an attacker origin while credentials are on — the
 * exact misconfiguration the tuupola/neomerx path produced when `credentials`
 * was left true).
 *
 * Wired ahead of the routing/auth stack (see config/middlewares.php), so the
 * OPTIONS preflight is answered HERE with a 204 before authentication runs —
 * the custom class must NOT depend on Slim's RouteContext, because routing
 * middleware runs later in the stack and the routing results do not exist yet
 * at this point.
 */

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    /** Wildcard origin — only safe while credentials stay OFF (see addCorsHeaders()). */
    private const ALLOW_ORIGIN = '*';

    private const ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE';

    // Fixed allow-list instead of reflecting Access-Control-Request-Headers.
    // Covers the auth + CSRF-token headers the admin client and the API send.
    private const ALLOW_HEADERS = 'Content-Type, X-Requested-With, X-Csrf-Token, Authorization, Accept';

    // Preflight cache lifetime (seconds). Mirrors the old Tuupola `cache` default.
    private const MAX_AGE = '20';

    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Preflight short-circuit: answer here, before routing/auth run. A browser
        // preflight is an OPTIONS request; we short-circuit every OPTIONS because no
        // route registers the OPTIONS verb (letting it through would 405, which the
        // browser treats as a failed preflight). The empty 204 needs a freshly minted
        // response — hence the injected ResponseFactory.
        if (strcasecmp($request->getMethod(), 'OPTIONS') === 0) {
            $response = $this->responseFactory->createResponse(204)
                ->withHeader('Access-Control-Max-Age', self::MAX_AGE);

            return $this->addCorsHeaders($response);
        }

        return $this->addCorsHeaders($handler->handle($request));
    }

    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', self::ALLOW_ORIGIN)
            ->withHeader('Access-Control-Allow-Methods', self::ALLOW_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS);

        // SECURITY: a wildcard Allow-Origin is only safe WITHOUT credentials.
        // Never send Access-Control-Allow-Credentials here — the cookie-session
        // CSRF defense (AuthyMiddleware) depends on credentialed cross-origin
        // requests being impossible, which a wildcard origin guarantees as long
        // as credentials stay off. If a future feature needs credentialed CORS,
        // switch to a strict per-request origin allowlist first.
        return $response->withoutHeader('Access-Control-Allow-Credentials');
    }
}
