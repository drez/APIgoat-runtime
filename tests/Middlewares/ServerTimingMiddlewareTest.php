<?php

namespace ApiGoat\Tests\Middlewares;

use ApiGoat\Middlewares\ServerTimingMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The header is only as useful as its coverage. A 401 or a 500 is exactly
 * the request you want timed — an auth failure still ran the whole
 * middleware stack — so these tests pin the ONE thing that decides whether
 * those responses carry a number: where the middleware sits relative to
 * Slim's error middleware.
 */
final class ServerTimingMiddlewareTest extends TestCase
{
    public function testStampsANormalResponse(): void
    {
        $res = $this->handle($this->app(outermost: true, throw: false));

        self::assertSame(200, $res->getStatusCode());
        self::assertMatchesRegularExpression('/^app;dur=[\d.]+$/', $res->getHeaderLine('Server-Timing'));
    }

    /**
     * The regression this file exists for: with the middleware added AFTER
     * addErrorMiddleware it is OUTERMOST, so it sees the response the error
     * handler built and can stamp it.
     */
    public function testStampsAResponseBuiltFromAThrownException(): void
    {
        $res = $this->handle($this->app(outermost: true, throw: true));

        self::assertSame(500, $res->getStatusCode());
        self::assertMatchesRegularExpression('/^app;dur=[\d.]+$/', $res->getHeaderLine('Server-Timing'));
    }

    /**
     * The old order, kept as the reason the new one exists: added BEFORE
     * addErrorMiddleware it sits inside, the exception unwinds straight past
     * it, and the error response leaves with no header at all.
     */
    public function testDoesNotStampWhenItSitsInsideTheErrorMiddleware(): void
    {
        $res = $this->handle($this->app(outermost: false, throw: true));

        self::assertSame(500, $res->getStatusCode());
        self::assertSame('', $res->getHeaderLine('Server-Timing'));
    }

    private function app(bool $outermost, bool $throw): App
    {
        $app = AppFactory::create();
        $app->get('/probe', static function ($request, ResponseInterface $response) use ($throw): ResponseInterface {
            if ($throw) {
                throw new \RuntimeException('boom');
            }
            $response->getBody()->write('ok');

            return $response;
        });

        $app->addRoutingMiddleware();
        // add() is LIFO — the LAST one added runs FIRST, i.e. outermost.
        if ($outermost) {
            $app->addErrorMiddleware(false, false, false);
            $app->add(new ServerTimingMiddleware());
        } else {
            $app->add(new ServerTimingMiddleware());
            $app->addErrorMiddleware(false, false, false);
        }

        return $app;
    }

    private function handle(App $app): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                '/probe',
                ['REQUEST_TIME_FLOAT' => microtime(true)]
            )
        );
    }
}
