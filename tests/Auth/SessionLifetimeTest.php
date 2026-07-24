<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\SessionLifetime;
use PHPUnit\Framework\TestCase;

final class SessionLifetimeTest extends TestCase
{
    private const KEYS = ['GC_SESSION_GUI_DAYS', 'GC_SESSION_API_DAYS', 'GC_SESSION_MCP_DAYS'];

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $k) {
            putenv($k);
        }
    }

    public function testDefaultsWhenUnset(): void
    {
        $this->assertSame(30, SessionLifetime::guiDays());
        $this->assertSame(90, SessionLifetime::apiDays());
        $this->assertSame(90, SessionLifetime::mcpDays());
        $this->assertNull(SessionLifetime::apiDaysFromEnv());
    }

    public function testValidValuesAreHonoured(): void
    {
        putenv('GC_SESSION_GUI_DAYS=7');
        putenv('GC_SESSION_API_DAYS=120');
        putenv('GC_SESSION_MCP_DAYS=365');
        $this->assertSame(7, SessionLifetime::guiDays());
        $this->assertSame(120, SessionLifetime::apiDays());
        $this->assertSame(120, SessionLifetime::apiDaysFromEnv());
        $this->assertSame(365, SessionLifetime::mcpDays());
    }

    public function testValuesAboveCapClampToCap(): void
    {
        putenv('GC_SESSION_GUI_DAYS=200');
        putenv('GC_SESSION_API_DAYS=999');
        putenv('GC_SESSION_MCP_DAYS=9999');
        $this->assertSame(90, SessionLifetime::guiDays());
        $this->assertSame(365, SessionLifetime::apiDays());
        $this->assertSame(365, SessionLifetime::mcpDays());
    }

    public function testGarbageFallsBackToDefault(): void
    {
        putenv('GC_SESSION_GUI_DAYS=abc');
        putenv('GC_SESSION_API_DAYS=0');
        putenv('GC_SESSION_MCP_DAYS=-5');
        $this->assertSame(30, SessionLifetime::guiDays());
        $this->assertSame(90, SessionLifetime::apiDays());
        $this->assertNull(SessionLifetime::apiDaysFromEnv());
        $this->assertSame(90, SessionLifetime::mcpDays());

        putenv('GC_SESSION_GUI_DAYS=');
        $this->assertSame(30, SessionLifetime::guiDays());
    }

    public function testMcpRefreshTtlInterval(): void
    {
        $this->assertSame(90, SessionLifetime::mcpRefreshTtl()->d);
        putenv('GC_SESSION_MCP_DAYS=365');
        $this->assertSame(365, SessionLifetime::mcpRefreshTtl()->d);
    }
}
