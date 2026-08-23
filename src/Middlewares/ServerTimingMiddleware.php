<?php

/*
 * Emits a Server-Timing header on every response: total PHP time since
 * REQUEST_TIME_FLOAT plus any named spans recorded via Utility\Timing
 * (e.g. `openai` from a project's AI gateway). Read it with
 * `curl -sD - -o /dev/null ... | grep -i server-timing`.
 *
 * Position: right after the security-headers add(), which leaves it inside
 * Slim's error middleware. That does NOT skew the number — the total is
 * measured from REQUEST_TIME_FLOAT, not from when this middleware starts —
 * but it does mean a request that ends in a THROWN exception unwinds past
 * here and its 500 carries no header. Normal responses, including the app's
 * own 4xx JSON envelopes, all get one.
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
