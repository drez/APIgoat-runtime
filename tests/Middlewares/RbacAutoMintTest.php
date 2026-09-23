<?php
// review-3 #19: api_rbac auto-mint gating. The recording of an unmatched
// request as a new rule is route discovery only: never for anonymous callers
// outside dev, only for routes that exist, and rate/row capped.

namespace ApiGoat\Tests\Middlewares;

require_once __DIR__ . '/../../src/Utility/MicroCache.php';
require_once __DIR__ . '/../../src/Utility/TableVersion.php';
require_once __DIR__ . '/../../src/Middlewares/RbacMiddleware.php';

use ApiGoat\Middlewares\RbacMiddleware;
use ApiGoat\Utility\MicroCache;
use PHPUnit\Framework\TestCase;

final class RbacAutoMintTest extends TestCase
{
    private static function noRules(): callable
    {
        return static fn (string $m, ?string $a): bool => false;
    }

    public function testCoreActionsOfAJsonRouteModelAreKnown(): void
    {
        foreach (['', 'list', 'get', 'create', 'update', 'edit', 'delete'] as $a) {
            self::assertTrue(RbacMiddleware::isKnownRoute('Contact', $a, ['Contact'], self::noRules()), $a);
        }
    }

    public function testUnknownModelIsNotKnown(): void
    {
        self::assertFalse(RbacMiddleware::isKnownRoute('Garbage', 'list', ['Contact'], self::noRules()));
    }

    public function testArbitraryActionOnAKnownModelIsNotKnown(): void
    {
        self::assertFalse(RbacMiddleware::isKnownRoute('Contact', 'xyz123', ['Contact'], self::noRules()));
    }

    public function testMalformedNamesAreRejectedBeforeAnyLookup(): void
    {
        $called = false;
        $spy = static function () use (&$called): bool { $called = true; return true; };
        self::assertFalse(RbacMiddleware::isKnownRoute('Contact;drop', 'list', ['Contact'], $spy));
        self::assertFalse(RbacMiddleware::isKnownRoute('Contact', '__construct', ['Contact'], $spy));
        self::assertFalse(RbacMiddleware::isKnownRoute('Contact', str_repeat('a', 80), ['Contact'], $spy));
        self::assertFalse($called);
    }

    public function testSeededCustomEndpointIsKnownThroughExistingRules(): void
    {
        $rules = static fn (string $m, ?string $a): bool => $m === 'Scheduling' && ($a === null || $a === 'createProspect');
        self::assertTrue(RbacMiddleware::isKnownRoute('Scheduling', 'createProspect', [], $rules));
        self::assertFalse(RbacMiddleware::isKnownRoute('Scheduling', 'other', [], $rules));
    }

    public function testCustomActionImplementedByTheServiceIsKnown(): void
    {
        self::assertTrue(RbacMiddleware::isKnownRoute('RbacMintProbe', 'scanReceipt', ['RbacMintProbe'], self::noRules()));
    }

    public function testMintTokensAreCappedPerWindow(): void
    {
        MicroCache::flushLocal();
        $granted = 0;
        for ($i = 0; $i < 10; $i++) {
            $granted += RbacMiddleware::takeMintToken(4) ? 1 : 0;
        }
        self::assertSame(4, $granted);
        self::assertFalse(RbacMiddleware::takeMintToken(0));
    }

    public function testAnonymousCallerOutsideDevNeverMints(): void
    {
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'gcRbacMintTest');
        }
        self::assertFalse(\defined('app_status') && \app_status === 'dev', 'test assumes a non-dev process');
        $_SESSION[\_AUTH_VAR] = new class {
            public function get($k) { return $k === 'connected' ? 'NO' : null; }
        };
        $mw = (new \ReflectionClass(RbacMiddleware::class))->newInstanceWithoutConstructor();
        $args = new \ReflectionProperty(RbacMiddleware::class, 'args');
        $args->setValue($mw, ['model' => 'Contact', 'action' => 'list', 'method' => 'GET']);
        $allowed = new \ReflectionMethod(RbacMiddleware::class, 'autoMintAllowed');
        // Returns before any route/DB lookup (no ApiRbacQuery is touched).
        self::assertFalse($allowed->invoke($mw));
        unset($_SESSION[\_AUTH_VAR]);
    }
}

namespace App;

/** A model Service exposing a custom action method (like ExpenseServiceWrapper::scanReceipt). */
class RbacMintProbeServiceWrapper
{
    public function scanReceipt() {}
}
