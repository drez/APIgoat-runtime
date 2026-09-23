<?php

namespace ApiGoat\Tests\Middlewares;

use ApiGoat\Middlewares\RoutePath;
use PHPUnit\Framework\TestCase;

/**
 * Review 2026-09-23: the mcp / _meta / geocode / .well-known exemptions were
 * substring matches, and every emitted route ends in a {params:.*} catch-all —
 * `GET /api/v1/Client/1/api/v1/mcp` was marked rbac_public and returned the row
 * with no authentication. The exemptions must match the exact route only.
 */
final class RoutePathTest extends TestCase
{
    public function testExactMcpRouteMatchesUnderASubDirAndAtTheRoot(): void
    {
        self::assertTrue(RoutePath::isMcp('/test/.admin/api/v1/mcp', '/test/.admin/'));
        self::assertTrue(RoutePath::isMcp('/api/v1/mcp', '/'));
        self::assertTrue(RoutePath::isMcp('/api/v1/mcp/', '/'));
    }

    public function testCatchAllModelRoutesCarryingTheExemptSegmentDoNotMatch(): void
    {
        self::assertFalse(RoutePath::isMcp('/api/v1/Client/1/api/v1/mcp', '/'));
        self::assertFalse(RoutePath::isMcp('/test/.admin/api/v1/Client/api/v1/mcp', '/test/.admin/'));
        self::assertFalse(RoutePath::isMcp('/api/v1/mcp/x', '/'));
        self::assertFalse(RoutePath::isMeta('/api/v1/Client/1/api/v1/_meta', '/'));
        self::assertFalse(RoutePath::isGeo('/api/v1/Client/1/api/v1/ApiGoat/geocode', '/'));
        self::assertFalse(RoutePath::isOAuthDiscovery('/test/.admin/Client/list/.well-known/x', '/test/.admin/'));
        self::assertFalse(RoutePath::isOAuthDiscovery('/Client/list/.well-known/oauth-protected-resource', '/'));
    }

    public function testTheOtherExemptRoutesStillMatch(): void
    {
        self::assertTrue(RoutePath::isMeta('/test/.admin/api/v1/_meta', '/test/.admin/'));
        self::assertTrue(RoutePath::isGeo('/api/v1/ApiGoat/geocode', '/'));
        self::assertTrue(RoutePath::isGeo('/api/v1/ApiGoat/reverseGeocode', '/'));
        self::assertTrue(RoutePath::isOAuthDiscovery('/test/.admin/.well-known/oauth-authorization-server', '/test/.admin/'));
        // with_mcp rewrites the origin-level document into a sub-dir app
        self::assertTrue(RoutePath::isOAuthDiscovery('/.well-known/oauth-protected-resource', '/test/.admin/'));
    }

    public function testAPathOutsideTheSubDirIsNotStrippedIntoAMatch(): void
    {
        self::assertFalse(RoutePath::isMcp('/other/api/v1/mcp', '/test/.admin/'));
    }
}
