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

use ApiGoat\Ops\Config;
use ApiGoat\Ops\QueryCounter;
use ApiGoat\Ops\RequestRecorder;
use ApiGoat\Ops\SlowQueryBuffer;
use ApiGoat\Ops\TimedStatement;
use ApiGoat\Utility\Timing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

final class ServerTimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        Timing::reset();
        QueryCounter::reset();
        // A fresh Propel connection is opened per request (config/Built/propel.php,
        // required from config/legacy.php, before this middleware ever runs) — so
        // whatever this process() call queued for a PRIOR request must not survive
        // into this one even if that request's flush never ran (e.g. it crashed
        // before RequestRecorder::defer()'s shutdown hook fired).
        SlowQueryBuffer::reset();

        // Security & Performance dashboards, Task 3: install the timed PDO
        // statement class that feeds QueryCounter/SlowQueryBuffer. Only when
        // Config::enabled() — a project that never declared with_ops_monitor
        // pays no per-query overhead at all.
        if (Config::enabled()) {
            $this->attachTimedStatement();
        }

        $start = (float) ($request->getServerParams()['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $response = $handler->handle($request);
        $total = (microtime(true) - $start) * 1000;

        // Security & Performance dashboards, Task 2: queue this request for
        // the ops_req_hour/ops_req_slow recorder. Config::enabled() is false
        // (and touches no DB) on any project that never declared
        // with_ops_monitor, so this is a no-op there.
        if (Config::enabled()) {
            $this->recordOpsRequest($request, $response, $total);
        }

        return $response->withHeader('Server-Timing', Timing::header($total));
    }

    /**
     * Sets PDO::ATTR_STATEMENT_CLASS on this request's Propel connection so
     * every prepare()/execute() from here on is timed. Wrapped so a Propel
     * hiccup (no connection yet, wrong datasource, ...) can never break the
     * request — telemetry setup is best-effort by contract everywhere else
     * in this feature too.
     */
    private function attachTimedStatement(): void
    {
        try {
            $con = \Propel::getConnection(\defined('_DATA_SRC') ? _DATA_SRC : null);
            $con->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TimedStatement::class]);
        } catch (\Throwable $e) {
            \error_log('[ops] TimedStatement attach failed: ' . $e->getMessage());
        }
    }

    /**
     * Never allowed to affect the response: recording is fire-and-forget
     * (RequestRecorder::defer() queues it for a shutdown hook), and every
     * step here is wrapped so a routing/PSR-7 surprise can't leak out of
     * this middleware either.
     */
    private function recordOpsRequest(ServerRequestInterface $request, ResponseInterface $response, float $total): void
    {
        try {
            // RouteContext::fromRequest() throws when routing never ran
            // (e.g. the request failed before addRoutingMiddleware saw it).
            try {
                $route = RouteContext::fromRequest($request)->getRoute();
            } catch (\Throwable $e) {
                $route = null;
            }

            RequestRecorder::defer([
                'route'    => RequestRecorder::routeKey($route?->getPattern()),
                'method'   => $request->getMethod(),
                'path'     => $request->getUri()->getPath(),
                'status'   => $response->getStatusCode(),
                'ms'       => (int) \round($total),
                'queries'  => QueryCounter::count(),
                'id_authy' => RequestRecorder::currentAuthyId(),
                'ip'       => $request->getServerParams()['REMOTE_ADDR'] ?? '',
                'ts'       => \time(),
            ]);
        } catch (\Throwable $e) {
            \error_log('[ops] defer failed: ' . $e->getMessage());
        }
    }
}
