<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Middlewares\ServerTimingMiddleware;
use ApiGoat\Middlewares\SessionReleaseMiddleware;
use ApiGoat\Ops\Config;
use ApiGoat\Ops\RequestRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * C1 regression (final review): ServerTimingMiddleware is the OUTERMOST
 * middleware, and Slim's RoutingMiddleware puts the routing attributes only
 * on the new request it passes INWARD — so RouteContext::fromRequest() on
 * the outer request always threw and every request was recorded under
 * '(unmatched)'. The route is now noted from inside routing
 * (SessionReleaseMiddleware -> RequestRecorder::noteRoute()) and read back
 * by ServerTimingMiddleware after handle().
 *
 * A real Slim app in the production middleware order
 * (P/.admin/config/middlewares.php): SessionRelease innermost, then
 * routing, then the error middleware, then ServerTiming outermost.
 */
final class RouteCaptureTest extends TestCase
{
    private $prevLog;
    private string $logFile;

    protected function setUp(): void
    {
        // attachTimedStatement() has no Propel connection here and logs that
        // via error_log() — kept off this run's stderr (R11).
        $this->logFile = \tempnam(\sys_get_temp_dir(), 'routecap');
        $this->prevLog = \ini_get('error_log');
        \ini_set('error_log', $this->logFile);
        Config::forceEnabled(true);
        RequestRecorder::takePendingForTest();
    }

    protected function tearDown(): void
    {
        RequestRecorder::takePendingForTest();
        Config::reset();
        \ini_set('error_log', (string) $this->prevLog);
        if (\is_file($this->logFile)) {
            \unlink($this->logFile);
        }
    }

    public function test_records_the_matched_route_pattern(): void
    {
        $res = $this->handle($this->app(), 'GET', '/x/42');

        self::assertSame(200, $res->getStatusCode());
        $pending = RequestRecorder::takePendingForTest();
        self::assertCount(1, $pending);
        self::assertSame('/x/{id}', $pending[0]['route']);
        self::assertSame('GET', $pending[0]['method']);
        self::assertSame('/x/42', $pending[0]['path']);
    }

    public function test_an_unrouted_request_stays_unmatched(): void
    {
        $res = $this->handle($this->app(), 'GET', '/nope');

        self::assertSame(404, $res->getStatusCode());
        $pending = RequestRecorder::takePendingForTest();
        self::assertCount(1, $pending);
        self::assertSame('(unmatched)', $pending[0]['route']);
    }

    public function test_a_previous_requests_route_never_leaks_into_the_next(): void
    {
        $app = $this->app();
        $this->handle($app, 'GET', '/x/1');
        $this->handle($app, 'GET', '/nope');

        $pending = RequestRecorder::takePendingForTest();
        self::assertSame(['/x/{id}', '(unmatched)'], \array_column($pending, 'route'));
    }

    private function app(): App
    {
        $app = AppFactory::create();
        $app->get('/x/{id}', static function ($request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('ok');

            return $response;
        });

        // Same LIFO order as config/middlewares.php.
        $app->add(new SessionReleaseMiddleware());
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        $app->add(new ServerTimingMiddleware());

        return $app;
    }

    private function handle(App $app, string $method, string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REQUEST_TIME_FLOAT' => \microtime(true)]);

        return $app->handle($request);
    }
}
