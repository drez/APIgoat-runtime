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
 */

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
        return $handler->handle($request);
    }
}
