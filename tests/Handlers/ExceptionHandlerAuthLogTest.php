<?php

use ApiGoat\Handlers\ExceptionHandler;
use JimTools\JwtAuth\Exceptions\AuthorizationException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once __DIR__ . '/../../src/Handlers/ExceptionHandler.php';

/**
 * apigoatacc monthly report 2026-10: 34 "Slim Application Error" blocks were
 * JwtAuthentication's AuthorizationException ("Token not found.") — an
 * expected 401 for a bearer route hit without a token (MCP clients before
 * OAuth, the mobile app before login, scanners) — logged with the full
 * 17-line trace of a server fault. The response was already a JSON 401; the
 * log line must be ONE line naming the request, never the trace. Any other
 * exception keeps the Slim renderer output.
 */
final class ExceptionHandlerAuthLogTest extends TestCase
{
    /** Handler whose logError() captures into a public $log array. */
    private function handler(): ExceptionHandler
    {
        $resolver = new class implements CallableResolverInterface {
            public function resolve($toResolve): callable { return is_string($toResolve) ? new $toResolve() : $toResolve; }
            public function resolveRoute($toResolve): callable { return $this->resolve($toResolve); }
            public function resolveMiddleware($toResolve): callable { return $this->resolve($toResolve); }
        };
        $container = new class implements ContainerInterface {
            public function get(string $id)
            {
                if ($id === \Selective\Config\Configuration::class) {
                    return new \Selective\Config\Configuration(['cors' => ['defaults' => ['origin' => '*']]]);
                }
                throw new RuntimeException("no $id");
            }
            public function has(string $id): bool { return $id === \Selective\Config\Configuration::class; }
        };
        $h = new class ($resolver, new ResponseFactory(), null, $container) extends ExceptionHandler {
            public array $log = [];
            public function __construct($r, $f, $l, $c) { parent::__construct($r, $f, $l, $c); }
            protected function logError(string $error): void { $this->log[] = $error; }
        };
        return $h;
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, 'https://app.test' . $path, ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Claude-User')
            ->withHeader('Accept', 'application/json');
    }

    public function testMissingBearerTokenLogsOneLineNotATrace(): void
    {
        $h = $this->handler();
        $res = $h($this->request('POST', '/api/v1/mcp'), AuthorizationException::noTokenFound(), false, true, true);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertCount(1, $h->log);
        $line = $h->log[0];
        $this->assertStringNotContainsString("\n", $line, 'one line, no trace');
        $this->assertStringNotContainsString('Slim Application Error', $line);
        foreach (['401', 'Token not found', 'POST /api/v1/mcp', 'Claude-User', '203.0.113.9'] as $needle) {
            $this->assertStringContainsString($needle, $line);
        }
    }

    public function testMissingBearerTokenIsNotLoggedWhenLoggingIsOff(): void
    {
        $h = $this->handler();
        $h($this->request('GET', '/api/v1/_meta'), AuthorizationException::noTokenFound(), false, false, false);
        $this->assertSame([], $h->log);
    }

    public function testOtherExceptionsKeepTheSlimRendererOutput(): void
    {
        $h = $this->handler();
        $h($this->request('GET', '/Client'), new RuntimeException('boom'), false, true, true);
        $this->assertCount(1, $h->log);
        $this->assertStringContainsString('Slim Application Error', $h->log[0]);
        $this->assertStringContainsString('boom', $h->log[0]);
    }
}
