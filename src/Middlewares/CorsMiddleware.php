<?php


/*
 * This middleware will append the response header Access-Control-Allow-Methods with all allowed methods
 */

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * Description of CorsMiddleware
 *
 * @author sysadmin
 */
class CorsMiddleware
{
    public function __construct()
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $routingResults = $routeContext->getRoutingResults();
        $methods = $routingResults->getAllowedMethods();

        $response = $handler->handle($request);

        $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        $response = $response->withHeader('Access-Control-Allow-Methods', implode(',', $methods));
        // Fixed allow-list instead of reflecting Access-Control-Request-Headers.
        $response = $response->withHeader(
            'Access-Control-Allow-Headers',
            'Content-Type, X-Requested-With, X-Csrf-Token, Authorization, Accept'
        );

        // SECURITY: a wildcard Allow-Origin is only safe WITHOUT credentials.
        // Never send Access-Control-Allow-Credentials here — the cookie-session
        // CSRF defense (AuthyMiddleware) depends on credentialed cross-origin
        // requests being impossible, which a wildcard origin guarantees as long
        // as credentials stay off. If a future feature needs credentialed CORS,
        // switch to a strict per-request origin allowlist first.
        $response = $response->withoutHeader('Access-Control-Allow-Credentials');

        return $response;
    }
}
