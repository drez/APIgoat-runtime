<?php

namespace App {
    /** Fake Peers: column constants are all GcTelemetrySummary::scope() reads. */
    class McpScopeFullPeer
    {
        const ID_TENANT = 'x.id_tenant';
        const ID_CREATION = 'x.id_creation';
        const ID_GROUP_CREATION = 'x.id_group_creation';
    }
    class McpScopeBarePeer
    {
    }
}

namespace ApiGoat\Tests\Mcp {

    use ApiGoat\Mcp\Tools\GcIdentityUpdate;
    use ApiGoat\Mcp\Tools\GcTelemetrySummary;
    use ApiGoat\Sessions\AuthySession;
    use PHPUnit\Framework\TestCase;

    /** review-3 Wave 3: telemetry_summary row scope; gc_identity_update needs unscoped Config w. */
    final class McpToolScopeTest extends TestCase
    {
        private function session(array $acl, $tenant = 5, bool $root = false): AuthySession
        {
            $s = new AuthySession();
            $s->set('id', 42);
            $s->set('connected', 'YES');
            $s->set('isRoot', $root);
            $s->set('id_tenant', $tenant);
            $s->IdPrimaryGroup = 9;
            $s->setGroups([[3, 'No']]);
            $s->accessControl = $acl;
            return $s;
        }

        public function testRootIsUnscoped(): void
        {
            self::assertSame([[], []], GcTelemetrySummary::scope($this->session([], null, true), '\\App\\McpScopeFullPeer'));
        }

        public function testAllGrantGetsTenantOnly(): void
        {
            [$w, $p] = GcTelemetrySummary::scope($this->session(['ClientEvent' => ['All' => 'r']]), '\\App\\McpScopeFullPeer');
            self::assertSame(['id_tenant = ?'], $w);
            self::assertSame([5], $p);
        }

        public function testEmptyTenantFailsClosed(): void
        {
            [$w] = GcTelemetrySummary::scope($this->session(['ClientEvent' => ['All' => 'r']], null), '\\App\\McpScopeFullPeer');
            self::assertSame(['1 = 0'], $w);
        }

        public function testOwnerGroupScope(): void
        {
            [$w, $p] = GcTelemetrySummary::scope($this->session(['ClientEvent' => ['Owner' => 'r', 'Group' => 'r']]), '\\App\\McpScopeFullPeer');
            self::assertSame(['id_tenant = ?', '(id_creation = ? OR id_group_creation IN (?,?))'], $w);
            self::assertSame([5, 42, 3, 9], $p);

            [$w, $p] = GcTelemetrySummary::scope($this->session(['ClientEvent' => ['Owner' => 'r']]), '\\App\\McpScopeFullPeer');
            self::assertSame(['id_tenant = ?', 'id_creation = ?'], $w);
            self::assertSame([5, 42], $p);
        }

        public function testOwnerScopeWithoutColumnFailsClosed(): void
        {
            [$w] = GcTelemetrySummary::scope($this->session(['ClientEvent' => ['Owner' => 'r']]), '\\App\\McpScopeBarePeer');
            self::assertSame(['1 = 0'], $w);
        }

        public function testNoGrantFailsClosed(): void
        {
            [$w] = GcTelemetrySummary::scope($this->session([]), '\\App\\McpScopeBarePeer');
            self::assertSame(['1 = 0'], $w);
        }

        public function testIdentityUpdateNeedsUnscopedConfigWrite(): void
        {
            self::assertTrue(GcIdentityUpdate::unscopedConfigWrite($this->session(['Config' => ['All' => 'rw']], null)));
            self::assertFalse(GcIdentityUpdate::unscopedConfigWrite($this->session(['Config' => ['Owner' => 'rw']], null)));
            self::assertFalse(GcIdentityUpdate::unscopedConfigWrite($this->session(['Config' => ['All' => 'r']], null)));
            // The identity file is host-wide: a tenant-bound user never writes it.
            self::assertFalse(GcIdentityUpdate::unscopedConfigWrite($this->session(['Config' => ['All' => 'rw']], 5)));
            self::assertTrue(GcIdentityUpdate::unscopedConfigWrite($this->session([], null, true)));
        }
    }
}
