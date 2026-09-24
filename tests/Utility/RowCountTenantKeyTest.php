<?php

namespace ApiGoat\Tests\Utility;

use ApiGoat\Sessions\AuthySession;
use ApiGoat\Utility\RowCount;
use PHPUnit\Framework\TestCase;

/** Review-3 Wave 3: menu row counts are tenant-scoped, so the cache key is too. */
final class RowCountTenantKeyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'AUTH');
        }
    }

    protected function tearDown(): void
    {
        unset($_SESSION[_AUTH_VAR]);
    }

    private function as(?int $tenant, bool $root = false): string
    {
        $s = new AuthySession();
        $s->set('connected', 'YES');
        $s->set('isRoot', $root);
        $s->set('id_tenant', $tenant);
        $_SESSION[_AUTH_VAR] = $s;
        return RowCount::cacheKey('Invoice');
    }

    public function testKeyFollowsTenantToken(): void
    {
        self::assertSame('Invoice|t1', $this->as(1));
        self::assertSame('Invoice|t2', $this->as(2));
        self::assertSame('Invoice|tnone', $this->as(null));
        self::assertSame('Invoice|all', $this->as(1, true));
        unset($_SESSION[_AUTH_VAR]);
        self::assertSame('Invoice|all', RowCount::cacheKey('Invoice'));
    }
}
