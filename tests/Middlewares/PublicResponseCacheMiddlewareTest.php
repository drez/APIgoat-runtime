<?php

namespace ApiGoat\Tests\Middlewares;

use ApiGoat\Middlewares\PublicResponseCacheMiddleware;
use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\PublicCacheMap;
use ApiGoat\Utility\TableVersion;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

if (!defined('_AUTH_VAR')) {
    define('_AUTH_VAR', 'gcTestAuth');
}

/**
 * Pins the outcome table of PublicResponseCacheMiddleware (OFF / BYPASS /
 * MISS / HIT / VERIFY) against a real Slim app: a stub stands in for
 * RouteParser (sets `parsed_args`), a counting route stands in for the
 * generated action. MicroCache runs on its per-process store here (no
 * apc.enable_cli), which is exactly why setUp flushes it.
 */
final class PublicResponseCacheMiddlewareTest extends TestCase
{
    private string $mapFile;
    private int $calls = 0;

    protected function setUp(): void
    {
        MicroCache::flushLocal();
        PublicCacheMap::reset();
        putenv('GC_HTTPCACHE_TTL=60');
        putenv('GC_HTTPCACHE_VERIFY');
        putenv('GC_HTTPCACHE_MAX_KB');
        unset($_SESSION[_AUTH_VAR]);
        $this->calls = 0;

        $this->mapFile = tempnam(sys_get_temp_dir(), 'gc-cachemap-');
        file_put_contents($this->mapFile, '<?php return ' . var_export([
            '_build' => 'build-test',
            'routes' => [
                'Thing/list/GET' => [
                    'ttl'           => 60,
                    'tables'        => ['thing'],
                    'params'        => null,
                    'bypass_params' => ['debug'],
                    'vary'          => [],
                ],
                'Strict/list/GET' => [
                    'ttl'           => 60,
                    'tables'        => ['strict'],
                    'params'        => ['page', 'q'],
                    'bypass_params' => [],
                    'vary'          => [],
                ],
            ],
        ], true) . ';');
        PublicCacheMap::load($this->mapFile);
    }

    protected function tearDown(): void
    {
        putenv('GC_HTTPCACHE_TTL');
        putenv('GC_HTTPCACHE_VERIFY');
        putenv('GC_HTTPCACHE_MAX_KB');
        unset($_SESSION[_AUTH_VAR]);
        @unlink($this->mapFile);
        PublicCacheMap::reset();
        MicroCache::flushLocal();
    }

    public function testOffWhenTtlIsZeroLeavesTheResponseUntouched(): void
    {
        putenv('GC_HTTPCACHE_TTL=0');
        $app = $this->app();

        $res = $this->get($app, '/api/v1/Thing/list');
        $res = $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertFalse($res->hasHeader('X-GC-Cache'));
        self::assertSame('no-store, no-cache, must-revalidate, max-age=0', $res->getHeaderLine('Cache-Control'));
    }

    public function testUndeclaredRouteIsUntouched(): void
    {
        $app = $this->app();

        $res = $this->get($app, '/api/v1/Other/list');
        $this->get($app, '/api/v1/Other/list');

        self::assertSame(2, $this->calls);
        self::assertFalse($res->hasHeader('X-GC-Cache'));
    }

    public function testNonApiRouteIsUntouched(): void
    {
        $app = $this->app();

        $res = $this->get($app, '/Thing/list');

        self::assertSame(1, $this->calls);
        self::assertFalse($res->hasHeader('X-GC-Cache'));
    }

    public function testMissThenHitRunsTheHandlerOnce(): void
    {
        $app  = $this->app();
        $seen = null;
        $this->onRequest = static function (ServerRequestInterface $r) use (&$seen): void {
            $seen = $r->getAttribute('gc_httpcache');
        };

        $miss = $this->get($app, '/api/v1/Thing/list');
        self::assertSame('MISS', $miss->getHeaderLine('X-GC-Cache'));
        self::assertSame('public, max-age=60', $miss->getHeaderLine('Cache-Control'));
        self::assertFalse($miss->hasHeader('Pragma'), 'handler no-store/Pragma replaced');
        self::assertFalse($miss->hasHeader('Expires'));
        self::assertSame('Authorization, Cookie', $miss->getHeaderLine('Vary'));
        self::assertSame('{"n":1}', (string) $miss->getBody());
        self::assertSame(60, $seen['ttl'] ?? null, 'MISS exposes key+ttl to the handler');
        self::assertStringStartsWith('gc:http:', $seen['key'] ?? '');

        $hit = $this->get($app, '/api/v1/Thing/list');
        self::assertSame(1, $this->calls, 'handler not run on HIT');
        self::assertSame('HIT', $hit->getHeaderLine('X-GC-Cache'));
        self::assertSame(200, $hit->getStatusCode());
        self::assertSame('{"n":1}', (string) $hit->getBody());
        self::assertSame('application/json', $hit->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=60', $hit->getHeaderLine('Cache-Control'));
        self::assertSame('Authorization, Cookie', $hit->getHeaderLine('Vary'));
        self::assertFalse($hit->hasHeader('Pragma'));
    }

    public function testAuthorizationHeaderBypasses(): void
    {
        $app = $this->app();
        $this->get($app, '/api/v1/Thing/list'); // primes the cache

        $res = $this->get($app, '/api/v1/Thing/list', ['Authorization' => 'Bearer x']);

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    /**
     * Apache's `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` (project
     * .htaccess) leaves an EMPTY Authorization header on every anonymous
     * request; only a non-empty value may bypass (found on the first local
     * probe: every declared route bypassed with reason auth-header).
     */
    public function testEmptyAuthorizationHeaderStillCaches(): void
    {
        $app = $this->app();
        $this->get($app, '/api/v1/Thing/list', ['Authorization' => '']);
        $res = $this->get($app, '/api/v1/Thing/list', ['Authorization' => '']);

        self::assertSame(1, $this->calls);
        self::assertSame('HIT', $res->getHeaderLine('X-GC-Cache'));
    }

    /**
     * Stale-while-revalidate: past its TTL but inside the grace window, an
     * entry is still served (STALE) while exactly one request refreshes it.
     */
    public function testStaleEntryIsServedWhileOneRequestRefreshes(): void
    {
        $app = $this->app();
        $key = null;
        $this->onRequest = static function (ServerRequestInterface $r) use (&$key): void {
            $info = $r->getAttribute('gc_httpcache');
            if (\is_array($info) && isset($info['key'])) {
                $key = $info['key'];
            }
        };
        $first = $this->get($app, '/api/v1/Thing/list');
        self::assertSame('MISS', $first->getHeaderLine('X-GC-Cache'));
        self::assertSame(1, $this->calls);
        self::assertIsString($key);

        // Age the entry past its TTL (still inside ttl + grace).
        $entry = MicroCache::get($key);
        self::assertIsArray($entry);
        $entry['fresh_until'] = time() - 1;
        MicroCache::put($key, 90, $entry);

        // First arrival takes the refresh lock and recomputes.
        $refresh = $this->get($app, '/api/v1/Thing/list');
        self::assertSame('MISS', $refresh->getHeaderLine('X-GC-Cache'));
        self::assertSame(2, $this->calls);

        // Age it again and pre-hold the lock: the next arrival must be served stale, no handler call.
        $entry = MicroCache::get($key);
        $entry['fresh_until'] = time() - 1;
        MicroCache::put($key, 90, $entry);
        MicroCache::put($key . ':lock', 10, 1);
        $stale = $this->get($app, '/api/v1/Thing/list');
        self::assertSame('STALE', $stale->getHeaderLine('X-GC-Cache'));
        self::assertSame(2, $this->calls);
        // The stale copy is the REFRESHED entry (the second handler run), not the original.
        self::assertSame((string) $refresh->getBody(), (string) $stale->getBody());
    }

    public function testRefreshLockIsTakenWithAnAtomicAdd(): void
    {
        self::assertTrue(MicroCache::add('gc:test:lock', 10, 1), 'first taker wins');
        self::assertFalse(MicroCache::add('gc:test:lock', 10, 2), 'second taker loses');
        self::assertSame(1, MicroCache::get('gc:test:lock'), 'and does not overwrite');
        MicroCache::forget('gc:test:lock');
        self::assertTrue(MicroCache::add('gc:test:lock', 10, 3), 'free again once released');
        self::assertFalse(MicroCache::add('gc:test:zero', 0, 1), 'no TTL, no entry');
        MicroCache::forget('gc:test:lock');
    }

    public function testXAuthorizationHeaderBypasses(): void
    {
        $app = $this->app();
        $this->get($app, '/api/v1/Thing/list');

        $res = $this->get($app, '/api/v1/Thing/list', ['X-Authorization' => 'Bearer x']);

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testConnectedGuiSessionBypasses(): void
    {
        $app = $this->app();
        $this->get($app, '/api/v1/Thing/list');

        $_SESSION[_AUTH_VAR] = new FakeAuthSession(['connected' => 'YES']);
        $res = $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));

        // A present-but-not-connected session object is still anonymous.
        $_SESSION[_AUTH_VAR] = new FakeAuthSession(['connected' => 'NO']);
        $res = $this->get($app, '/api/v1/Thing/list');
        self::assertSame(2, $this->calls);
        self::assertSame('HIT', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testSkipAttributeBypasses(): void
    {
        $app = $this->app(skipAttr: true);

        $res = $this->get($app, '/api/v1/Thing/list');
        $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testNon200IsNotStored(): void
    {
        $app = $this->app(status: 404);

        $res = $this->get($app, '/api/v1/Thing/list');
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
        self::assertSame(404, $res->getStatusCode());
        self::assertSame('no-store, no-cache, must-revalidate, max-age=0', $res->getHeaderLine('Cache-Control'), 'other headers untouched');

        $this->get($app, '/api/v1/Thing/list');
        self::assertSame(2, $this->calls);
    }

    public function testNonJsonIsNotStored(): void
    {
        $app = $this->app(ctype: 'text/plain');

        $res = $this->get($app, '/api/v1/Thing/list');
        $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testSetCookieIsNotStored(): void
    {
        $app = $this->app(setCookie: true);

        $res = $this->get($app, '/api/v1/Thing/list');
        $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
        self::assertSame('sid=1', $res->getHeaderLine('Set-Cookie'));
    }

    public function testOversizedBodyIsNotStored(): void
    {
        putenv('GC_HTTPCACHE_MAX_KB=1');
        $app = $this->app(body: '{"pad":"' . str_repeat('x', 2048) . '"}');

        $res = $this->get($app, '/api/v1/Thing/list');
        $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testHandlerOptOutViaBypassHeaderIsNotStored(): void
    {
        $app = $this->app(optOut: true);

        $res = $this->get($app, '/api/v1/Thing/list');
        $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testQueryOrderIsInsensitive(): void
    {
        $app = $this->app();

        $this->get($app, '/api/v1/Thing/list?a=1&b=2');
        $res = $this->get($app, '/api/v1/Thing/list?b=2&a=1');

        self::assertSame(1, $this->calls);
        self::assertSame('HIT', $res->getHeaderLine('X-GC-Cache'));

        // ...but a different VALUE is a different entry.
        $res = $this->get($app, '/api/v1/Thing/list?b=3&a=1');
        self::assertSame(2, $this->calls);
        self::assertSame('MISS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testBypassParamBypasses(): void
    {
        $app = $this->app();

        $res = $this->get($app, '/api/v1/Thing/list?debug=1');
        $this->get($app, '/api/v1/Thing/list?debug=1');

        self::assertSame(2, $this->calls);
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
    }

    public function testParamsAllowlistViolationBypasses(): void
    {
        $app = $this->app();

        $ok = $this->get($app, '/api/v1/Strict/list?page=2&q=x');
        self::assertSame('MISS', $ok->getHeaderLine('X-GC-Cache'));

        $res = $this->get($app, '/api/v1/Strict/list?page=2&other=1');
        self::assertSame('BYPASS', $res->getHeaderLine('X-GC-Cache'));
        self::assertSame(2, $this->calls);
    }

    public function testTableBumpInvalidates(): void
    {
        $app = $this->app();

        $this->get($app, '/api/v1/Thing/list');
        self::assertSame('HIT', $this->get($app, '/api/v1/Thing/list')->getHeaderLine('X-GC-Cache'));

        TableVersion::bump('thing');
        $res = $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls);
        self::assertSame('MISS', $res->getHeaderLine('X-GC-Cache'));

        // An unrelated table does not.
        TableVersion::bump('unrelated');
        self::assertSame('HIT', $this->get($app, '/api/v1/Thing/list')->getHeaderLine('X-GC-Cache'));
        self::assertSame(2, $this->calls);
    }

    public function testVerifyModeRunsTheHandlerAndServesFresh(): void
    {
        $app = $this->app();
        $this->get($app, '/api/v1/Thing/list');

        putenv('GC_HTTPCACHE_VERIFY=1');
        $res = $this->get($app, '/api/v1/Thing/list');

        self::assertSame(2, $this->calls, 'handler ran despite the hit');
        self::assertSame('VERIFY', $res->getHeaderLine('X-GC-Cache'));
        self::assertSame('{"n":2}', (string) $res->getBody(), 'fresh bytes served (and they diverge: n counts)');
    }

    /**
     * parsed_args['query'] is whatever RouteParser put there; a non-array
     * must not break the request — the middleware falls back to the
     * request's own query params and carries on.
     */
    public function testMalformedParsedQueryFallsBackToRequestQuery(): void
    {
        $app = $this->app(poisonArgs: true);

        $res = $this->get($app, '/api/v1/Thing/list?a=1');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('MISS', $res->getHeaderLine('X-GC-Cache'));

        self::assertSame('HIT', $this->get($app, '/api/v1/Thing/list?a=1')->getHeaderLine('X-GC-Cache'));
        self::assertSame('MISS', $this->get($app, '/api/v1/Thing/list?a=2')->getHeaderLine('X-GC-Cache'));
        self::assertSame(2, $this->calls);
    }

    /** @var callable|null */
    private $onRequest = null;

    private function app(
        int $status = 200,
        string $ctype = 'application/json',
        bool $setCookie = false,
        bool $optOut = false,
        bool $skipAttr = false,
        bool $poisonArgs = false,
        ?string $body = null
    ): App {
        $app   = AppFactory::create();
        $calls = &$this->calls;
        $self  = $this;

        $handler = function (ServerRequestInterface $request, ResponseInterface $response) use (&$calls, $status, $ctype, $setCookie, $optOut, $body, $self): ResponseInterface {
            $calls++;
            if ($self->onRequest) {
                ($self->onRequest)($request);
            }
            $response->getBody()->write($body ?? '{"n":' . $calls . '}');
            // What ApiResponse::getResponse() stamps on every generated route.
            $response = $response->withStatus($status)
                ->withHeader('Content-Type', $ctype)
                ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->withHeader('Pragma', 'no-cache');
            if ($setCookie) {
                $response = $response->withHeader('Set-Cookie', 'sid=1');
            }
            if ($optOut) {
                $response = $response->withHeader('X-GC-Cache', 'BYPASS');
            }
            return $response;
        };
        $app->get('/api/v1/{model}/{action}', $handler);
        $app->get('/{model}/{action}', $handler);

        // add() is LIFO: routing innermost, then the cache, then the
        // RouteParser stand-in OUTERMOST so parsed_args exists when the cache runs.
        $app->addRoutingMiddleware();
        $app->add(new PublicResponseCacheMiddleware());
        $app->add(new class($skipAttr, $poisonArgs) implements MiddlewareInterface {
            public function __construct(private bool $skip, private bool $poison)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $path  = $request->getUri()->getPath();
                $isApi = (bool) preg_match('#^/api/v[0-9]+/#', $path);
                $rest  = trim(preg_replace('#^/api/v[0-9]+/#', '', $path), '/');
                $parts = explode('/', $rest);
                $request = $request->withAttribute('parsed_args', [
                    'method' => $request->getMethod(),
                    'model'  => $parts[0] ?? '',
                    'action' => $parts[1] ?? 'list',
                    'id'     => '',
                    'route'  => $rest,
                    'is_api' => $isApi,
                    'query'  => $this->poison ? 'not-an-array' : $request->getQueryParams(),
                ]);
                if ($this->skip) {
                    $request = $request->withAttribute('gc_httpcache_skip', true);
                }
                return $handler->handle($request);
            }
        });
        $app->addErrorMiddleware(false, false, false);

        return $app;
    }

    /** @param array<string,string> $headers */
    private function get(App $app, string $uri, array $headers = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        return $app->handle($req);
    }
}

/** Minimal stand-in for the AuthySession stored in $_SESSION[_AUTH_VAR]. */
final class FakeAuthSession
{
    public function __construct(private array $data)
    {
    }

    public function get(string $k): mixed
    {
        return $this->data[$k] ?? null;
    }
}
