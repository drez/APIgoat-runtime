<?php

/*
 * Emits a Server-Timing header on every response: total PHP time since
 * REQUEST_TIME_FLOAT plus any named spans recorded via Utility\Timing
 * (e.g. `openai` from a project's AI gateway). Read it with
 * `curl -sD - -o /dev/null ... | grep -i server-timing`.
 *
 * Position: add() it AFTER addErrorMiddleware so it is the OUTERMOST
 * middleware and wraps the error middleware. It first sat inside, which
 * did not skew the number — the total is measured from REQUEST_TIME_FLOAT,
 * not from when this middleware starts — but a request ending in a THROWN
 * exception unwound straight past it, so every 401 from JwtAuthentication
 * and every 500 left with no header: precisely the requests worth timing,
 * since an auth failure still runs the whole stack. Wrapping the error
 * middleware means the response it builds gets stamped too.
 * ServerTimingMiddlewareTest pins both orders.
 */

namespace ApiGoat\Middlewares;

use ApiGoat\Utility\Timing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ServerTimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Timing::reset();
        $start = (float) ($request->getServerParams()['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $response = $handler->handle($request);
        $total = (microtime(true) - $start) * 1000;
        return $response->withHeader('Server-Timing', Timing::header($total));
    }
}
