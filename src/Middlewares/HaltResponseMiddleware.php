<?php

declare(strict_types=1);

/*
 * Turns a HaltResponse that escaped the route handler back into a normal
 * PSR-7 response, INSIDE the security middlewares (final review I-1).
 *
 * Background: `halt()` replaced the `die(json_encode(...))` that generated
 * services used to short-circuit with, precisely so the response would travel
 * back up Slim's middleware stack and pick up CORS, the security headers, the
 * server-timing stamp and the session release. The emitted route loops
 * (Classes/Routes.php) catch HaltResponse per closure to make that happen.
 *
 * That catch is per-closure, so it only covers the closures the emitter writes.
 * Anything else that halts — the two hand-written generated closures
 * (`api/v1/Authy/{a}`, `api/v1/ApiGoat/sendEmail`), and every route a project
 * registers itself in `config/routes.php` — unwinds all the way to Slim's error
 * middleware. ExceptionHandler does special-case HaltResponse and renders the
 * payload rather than a 500, but addErrorMiddleware() is registered AFTER
 * SecurityHeadersMiddleware/CorsMiddleware, i.e. it WRAPS them: the response it
 * builds has already passed them by, and carries neither. That is the exact
 * middleware-bypass `halt()` exists to remove, one project route away.
 *
 * Registering this middleware FIRST (`$app->add()` is LIFO, so first added =
 * innermost, closest to the route) makes the conversion happen before any other
 * middleware's response phase runs. From there on the halt payload is an
 * ordinary response and every middleware in the stack stamps it — no matter
 * which closure it came from. The per-closure catches stay for projects that
 * have not picked up the new middlewares.php line yet; where both are present
 * the closure catch simply wins and this never fires.
 *
 * Only HaltResponse is caught. Every other Throwable propagates untouched to
 * the error middleware.
 */

namespace ApiGoat\Middlewares;

use ApiGoat\Http\HaltResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HaltResponseMiddleware implements MiddlewareInterface
{
    /** @var ResponseFactoryInterface|null */
    private $responseFactory;

    /**
     * The factory is optional so the middleware resolves through Slim's
     * CallableResolver in projects whose container.php (project-owned, never
     * drift-synced) has no entry for it — same shape as AuthyMiddleware.
     */
    public function __construct(?ResponseFactoryInterface $responseFactory = null)
    {
        $this->responseFactory = $responseFactory;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HaltResponse $halt) {
            return $halt->applyTo($this->newResponse());
        }
    }

    private function newResponse(): ResponseInterface
    {
        if ($this->responseFactory !== null) {
            return $this->responseFactory->createResponse();
        }
        return (new \Slim\Psr7\Factory\ResponseFactory())->createResponse();
    }
}
