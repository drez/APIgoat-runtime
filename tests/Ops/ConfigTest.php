<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Config;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Config.php';

/**
 * Pure logic only (R1): no _BASE_DIR is ever defined in this test file, so
 * enabled() stays false and get()/all() only ever read the built-in
 * defaults or an override — never the filesystem. That matters beyond this
 * file too: ServerTimingMiddlewareTest (tests/Middlewares) shares a process
 * with this suite in CI and asserts Config::enabled() is false there with
 * nothing else defining _BASE_DIR first; a test here that defined it would
 * leak into that assertion.
 *
 * The "manifest file really exists" path (enabled() === true, get() reading
 * a real config/Built/ops_monitor.php) is exercised for real by
 * P/.admin/tests/Custom/OpsRequestRecorderTest.php, which runs inside a
 * project that actually declares with_ops_monitor.
 */
final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_enabled_is_false_without_base_dir(): void
    {
        $this->assertFalse(\defined('_BASE_DIR'), 'a prior test defined _BASE_DIR; this assertion is no longer meaningful');
        $this->assertFalse(Config::enabled());
    }

    public function test_defaults(): void
    {
        $this->assertSame(1000, Config::get('slow_ms'));
        $this->assertSame(250, Config::get('slow_query_ms'));
        $this->assertSame(14, Config::get('raw_days'));
        $this->assertSame(180, Config::get('rollup_days'));
        $this->assertSame('none', Config::get('server_source'));
        $this->assertSame('Admin', Config::get('report_to'));
        $this->assertSame('', Config::get('snapshot_path'));
    }

    public function test_unknown_key_returns_null(): void
    {
        $this->assertNull(Config::get('nope'));
    }

    public function test_override_replaces_values(): void
    {
        Config::override(['slow_ms' => 42]);

        $this->assertSame(42, Config::get('slow_ms'));
        // Every key not named in the override still falls back to its default.
        $this->assertSame(250, Config::get('slow_query_ms'));
    }

    public function test_override_null_reverts_to_the_manifest_path(): void
    {
        Config::override(['slow_ms' => 42]);
        Config::override(null);

        $this->assertSame(1000, Config::get('slow_ms'));
    }

    public function test_reset_clears_the_override_too(): void
    {
        Config::override(['slow_ms' => 42]);
        Config::reset();

        $this->assertSame(1000, Config::get('slow_ms'));
    }
}
