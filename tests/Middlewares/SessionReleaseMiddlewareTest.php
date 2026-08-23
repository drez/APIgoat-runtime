<?php

namespace ApiGoat\Tests\Middlewares;

use ApiGoat\Middlewares\SessionReleaseMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Every process() case runs in its own PHP process: session_status() is
 * global, so a test that closes the session would otherwise decide the
 * outcome of the one that expects it still open.
 */
final class SessionReleaseMiddlewareTest extends TestCase
{
    public function testReleasesOnlyForBearer(): void
    {
        self::assertTrue(SessionReleaseMiddleware::shouldRelease('Bearer abc.def'));
        self::assertTrue(SessionReleaseMiddleware::shouldRelease('bearer abc'));
        self::assertFalse(SessionReleaseMiddleware::shouldRelease(''));
        self::assertFalse(SessionReleaseMiddleware::shouldRelease('Basic xyz'));
        // A token that merely CONTAINS the word must not count — the scheme
        // has to be at position 0.
        self::assertFalse(SessionReleaseMiddleware::shouldRelease('Basic Bearer abc'));
    }

    /** The predicate is only half the job; this is the half that takes the lock off. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testProcessClosesTheSessionForABearerRequest(): void
    {
        $this->startSession();
        $handlerSawSession = null;

        $res = (new SessionReleaseMiddleware())->process(
            $this->request('/api/v1/Tutor/health')->withHeader('Authorization', 'Bearer tok'),
            $this->handler(static function () use (&$handlerSawSession): void {
                // Closed, but STILL READABLE in-process — this is exactly what
                // the actions downstream rely on.
                $handlerSawSession = $_SESSION['who'] ?? null;
            })
        );

        self::assertSame(PHP_SESSION_NONE, session_status(), 'the lock was released');
        self::assertSame('kid', $handlerSawSession, '$_SESSION still readable after close');
        self::assertSame(200, $res->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testProcessLeavesACookieSessionAlone(): void
    {
        $this->startSession();

        (new SessionReleaseMiddleware())->process(
            $this->request('/index.php'),
            $this->handler(static function (): void {})
        );

        // A browser request may still legitimately write to $_SESSION after
        // the handler runs (SyncConnectService's OAuth state has to survive a
        // redirect), so its lock must be held to the end of the script.
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testProcessIsSafeWhenThereIsNoSessionAtAll(): void
    {
        // CLI / a request that never bootstrapped a session: guarding on
        // session_status() is what keeps this from emitting a PHP warning,
        // and phpunit.xml runs with failOnWarning="true".
        $res = (new SessionReleaseMiddleware())->process(
            $this->request('/api/v1/Tutor/health')->withHeader('Authorization', 'Bearer tok'),
            $this->handler(static function (): void {})
        );
        self::assertSame(200, $res->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testFallsBackToXAuthorization(): void
    {
        // Some hosts strip Authorization before PHP sees it; the app's own
        // .htaccess re-presents it as X-Authorization.
        $this->startSession();
        (new SessionReleaseMiddleware())->process(
            $this->request('/api/v1/Tutor/health')->withHeader('X-Authorization', 'Bearer tok'),
            $this->handler(static function (): void {})
        );
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    private function request(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'https://gc.local' . $path);
    }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['who'] = 'kid';
    }

    private function handler(callable $onHandle): RequestHandlerInterface
    {
        return new class ($onHandle) implements RequestHandlerInterface {
            /** @var callable */
            private $onHandle;

            public function __construct(callable $onHandle)
            {
                $this->onHandle = $onHandle;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->onHandle)($request);
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }
}
