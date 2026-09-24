<?php

namespace ApiGoat\Tests\Security;

use ApiGoat\Middlewares\AuthyMiddleware;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * The template reads the outcome of an impersonation (iarc) switch from the
 * X-Gc-Iarc response header: 'switched' on success, 'refused' on a csrf
 * mismatch / not-allowed / unknown target; absent when no switch was asked.
 */
final class IarcOutcomeHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'AUTH');
        }
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    /** Run the private checkUserSwitch and read the recorded outcome. */
    private function outcome(AuthySession $session, array $data): array
    {
        $_SESSION[_AUTH_VAR] = $session;
        $ref = new \ReflectionClass(AuthyMiddleware::class);
        $mw  = $ref->newInstanceWithoutConstructor();
        $args = $ref->getProperty('args');
        $args->setAccessible(true);
        $args->setValue($mw, ['data' => $data]);
        $m = $ref->getMethod('checkUserSwitch');
        $m->setAccessible(true);
        $switched = @$m->invoke($mw, null);
        $o = $ref->getProperty('iarcOutcome');
        $o->setAccessible(true);
        return [$switched, $o->getValue($mw)];
    }

    private function session(bool $root): AuthySession
    {
        $s = new AuthySession();
        $s->set('id', 5);
        $s->set('connected', 'YES');
        $s->set('isRoot', $root);
        return $s;
    }

    public function testNoIarcNoOutcome(): void
    {
        self::assertSame([false, null], $this->outcome($this->session(true), []));
    }

    public function testNonRootIsRefused(): void
    {
        self::assertSame([false, 'refused'], $this->outcome($this->session(false), ['iarc' => 9, 'iarc_csrf' => 'x']));
    }

    public function testCsrfMismatchIsRefused(): void
    {
        $s = $this->session(true);
        $s->sessVar['IarcCsrf'] = 'right-token';
        self::assertSame([false, 'refused'], $this->outcome($s, ['iarc' => 9, 'iarc_csrf' => 'wrong']));
        self::assertSame([false, 'refused'], $this->outcome($s, ['iarc' => 9]));
    }

    public function testHeaderStamping(): void
    {
        $r = new Response(200);
        self::assertSame('switched', AuthyMiddleware::withIarcOutcome($r, 'switched')->getHeaderLine('X-Gc-Iarc'));
        self::assertSame('refused', AuthyMiddleware::withIarcOutcome($r, 'refused')->getHeaderLine('X-Gc-Iarc'));
        self::assertFalse(AuthyMiddleware::withIarcOutcome($r, null)->hasHeader('X-Gc-Iarc'));
        self::assertSame(403, AuthyMiddleware::withIarcOutcome(new Response(403), 'refused')->getStatusCode());
    }
}
