<?php

namespace ApiGoat\Tests\Mcp;

use ApiGoat\Mcp\Tools\AbstractCrmTool;
use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

/**
 * The cross-request MCP catalog cache must not outlive a rights change on the
 * same user (review-3 Wave 3): the key hashes the session's current grants.
 */
final class CatalogCacheKeyTest extends TestCase
{
    private function session(array $acl, ?int $tenant = null): AuthySession
    {
        $s = new AuthySession();
        $s->set('id', 7);
        $s->accessControl = $acl;
        if ($tenant !== null) {
            $s->set('id_tenant', $tenant);
        }
        return $s;
    }

    public function testSameGrantsSameKey(): void
    {
        $a = AbstractCrmTool::catalogCacheKey($this->session(['Invoice' => ['All' => 'rw'], 'Contact' => ['Owner' => 'r']]), 'b1');
        $b = AbstractCrmTool::catalogCacheKey($this->session(['Contact' => ['Owner' => 'r'], 'Invoice' => ['All' => 'rw']]), 'b1');
        self::assertSame($a, $b, 'grant order must not matter');
    }

    public function testRightsChangeChangesKey(): void
    {
        $a = AbstractCrmTool::catalogCacheKey($this->session(['Invoice' => ['All' => 'rw']]), 'b1');
        $b = AbstractCrmTool::catalogCacheKey($this->session(['Invoice' => ['All' => 'r']]), 'b1');
        $c = AbstractCrmTool::catalogCacheKey($this->session(['Invoice' => ['Owner' => 'rw']]), 'b1');
        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
    }

    public function testTenantAndBuildChangeKey(): void
    {
        $acl = ['Invoice' => ['All' => 'rw']];
        $base = AbstractCrmTool::catalogCacheKey($this->session($acl), 'b1');
        self::assertNotSame($base, AbstractCrmTool::catalogCacheKey($this->session($acl, 3), 'b1'));
        self::assertNotSame($base, AbstractCrmTool::catalogCacheKey($this->session($acl), 'b2'));
    }
}
