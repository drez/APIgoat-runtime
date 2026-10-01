<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Config;
use PHPUnit\Framework\TestCase;

/** with_ops_monitor slow_routes: per-route slow thresholds for routes that are slow by design (LLM, MCP). */
final class ConfigSlowRoutesTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_default_threshold_without_slow_routes(): void
    {
        Config::override(['slow_ms' => 1000]);
        $this->assertSame(1000, Config::slowMsFor('/api/v1/mcp'));
        $this->assertSame(1000, Config::slowMsFor(null));
    }

    public function test_exact_route_and_longest_prefix_win(): void
    {
        Config::override(['slow_ms' => 1000, 'slow_routes' => [
            '/api/v1/mcp' => 5000,
            '/.admin/api/v1/Ai/*' => 6000,
            '/.admin/api/v1/*' => 2000,
        ]]);
        $this->assertSame(5000, Config::slowMsFor('/api/v1/mcp'));
        $this->assertSame(1000, Config::slowMsFor('/api/v1/mcp/extra'), 'no * = exact only');
        $this->assertSame(6000, Config::slowMsFor('/.admin/api/v1/Ai/descriptionAssist'));
        $this->assertSame(2000, Config::slowMsFor('/.admin/api/v1/PublicProduct/search'));
        $this->assertSame(1000, Config::slowMsFor('/Dashboard/finance'));
    }

    public function test_bad_entries_are_ignored(): void
    {
        Config::override(['slow_ms' => 1000, 'slow_routes' => ['/x' => 'fast', '/y' => -5, '/z' => 3000]]);
        $this->assertSame(1000, Config::slowMsFor('/x'));
        $this->assertSame(1000, Config::slowMsFor('/y'));
        $this->assertSame(3000, Config::slowMsFor('/z'));
    }
}
