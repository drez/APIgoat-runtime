<?php

namespace ApiGoat\Tests\Middlewares;

use ApiGoat\Http\HaltResponse;
use ApiGoat\Middlewares\HaltResponseMiddleware;
use ApiGoat\Middlewares\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Final review I-1. `halt()` exists so a short-circuited response still travels
 * back up the middleware stack. The emitted route loops catch HaltResponse per
 * closure, which covers the closures the EMITTER writes — but not the two
 * hand-written generated ones (api/v1/Authy, ApiGoat/sendEmail) and not a
 * single project-registered route in config/routes.php. Those unwind to Slim's
 * error middleware, which is registered AFTER SecurityHeaders/CORS and
 * therefore WRAPS them: the response it builds has already passed them by and
 * carries none of their headers — the exact bypass halt() removes.
 *
 * These tests pin the fix and the reason it is needed, by asserting on
 * X-Frame-Options (a SecurityHeadersMiddleware header) for a halt thrown from a
 * closure with NO catch of its own.
 */
final class HaltResponseMiddlewareTest extends TestCase
{
    public function testHaltFromAnUncaughtClosureKeepsTheSecurityHeaders(): void
    {
        $res = $this->handle($this->app(withHaltMiddleware: true));

        self::assertSame(418, $res->getStatusCode());
        self::assertSame('{"halted":true}', (string) $res->getBody());
        self::assertSame('application/json', $res->getHeaderLine('Content-Type'));
        self::assertSame('SAMEORIGIN', $res->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * The old behaviour, kept as the reason the middleware exists: the halt
     * unwinds past SecurityHeaders to the error middleware, and whatever comes
     * back — a 500 here, the rendered payload in the real app where
     * ExceptionHandler special-cases HaltResponse — carries none of the
     * headers, because the error middleware WRAPS them.
     */
    public function testWithoutTheMiddlewareTheHeadersAreMissed(): void
    {
        $res = $this->handle($this->app(withHaltMiddleware: false));

        self::assertNotSame(418, $res->getStatusCode());
        self::assertSame('', $res->getHeaderLine('X-Frame-Options'));
    }

    /** Anything that is not a HaltResponse still reaches the error middleware. */
    public function testOtherExceptionsArePassedThrough(): void
    {
        $res = $this->handle($this->app(withHaltMiddleware: true, throwOther: true));

        self::assertSame(500, $res->getStatusCode());
    }

    /** A normal response is untouched. */
    public function testNormalResponseIsUnchanged(): void
    {
        $res = $this->handle($this->app(withHaltMiddleware: true, halt: false));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('ok', (string) $res->getBody());
    }

    private function app(bool $withHaltMiddleware, bool $halt = true, bool $throwOther = false): App
    {
        $app = AppFactory::create();
        // A project-owned route: no per-closure HaltResponse catch anywhere.
        $app->get('/probe', static function ($request, ResponseInterface $response) use ($halt, $throwOther): ResponseInterface {
            if ($throwOther) {
                throw new \RuntimeException('boom');
            }
            if ($halt) {
                throw new HaltResponse('{"halted":true}', 418, ['Content-Type' => 'application/json']);
            }
            $response->getBody()->write('ok');

            return $response;
        });

        // add() is LIFO: the FIRST one added is innermost, closest to the route.
        if ($withHaltMiddleware) {
            $app->add(new HaltResponseMiddleware());
        }
        $app->addRoutingMiddleware();
        $app->add(new SecurityHeadersMiddleware());
        $app->addErrorMiddleware(false, false, false);

        return $app;
    }

    private function handle(App $app): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/probe')
        );
    }
}
