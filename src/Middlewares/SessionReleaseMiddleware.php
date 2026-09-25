<?php

/*
 * Releases the PHP session (write + unlock) before the route handler runs,
 * for Bearer-authenticated requests only.
 *
 * Why: the default file session handler holds an EXCLUSIVE lock from
 * session_start() until the script ends. A mobile client that fans out five
 * API calls at once therefore has them executed one after another on the
 * server — measured on prod 2026-08-23 as a 0.37→0.52→0.68→0.77→0.92 s
 * staircase with the session cookie, against 0.56 s wall without it.
 *
 * Bearer requests re-hydrate identity from the token on every call
 * (OAuthResourceMiddleware), so nothing the action writes to $_SESSION needs
 * to survive: $_SESSION stays readable in-process after close, only the
 * write-back is dropped. The one runtime writer that genuinely needs
 * persistence — SyncConnectService's `sync_oauth_state`, which has to survive
 * a browser redirect — is a cookie-session flow and never carries a Bearer
 * header, so this never fires for it.
 *
 * Must be registered INNERMOST (first $app->add()) so every middleware that
 * reads or writes the session (OAuth hydrate, Authy, Rbac) has already run.
 *
 * Being the first runtime middleware inside routing, it is also where the
 * ops recorder learns the matched route pattern (noteRoute() below).
 */

namespace ApiGoat\Middlewares;

use ApiGoat\Ops\RequestRecorder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

final class SessionReleaseMiddleware implements MiddlewareInterface
{
    public static function shouldRelease(string $authorizationHeader): bool
    {
        return stripos($authorizationHeader, 'Bearer ') === 0;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth === '') {
            $auth = $request->getHeaderLine('X-Authorization');
        }
        if (self::shouldRelease($auth) && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        self::noteRoute($request);
        return $handler->handle($request);
    }

    /**
     * Ops telemetry (final review C1): this is the first runtime middleware
     * INSIDE Slim's routing, so $request here carries the routing attributes
     * the outermost ServerTimingMiddleware never sees. Hand the matched
     * pattern to RequestRecorder for it. Best-effort — never breaks the
     * request.
     */
    private static function noteRoute(ServerRequestInterface $request): void
    {
        try {
            $route = RouteContext::fromRequest($request)->getRoute();
            RequestRecorder::noteRoute($route?->getPattern());
        } catch (\Throwable $e) {
            // routing did not run for this request; it stays '(unmatched)'
        }
    }
}
