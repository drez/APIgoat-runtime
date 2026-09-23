<?php
namespace ApiGoat\Middlewares;

/**
 * Exact route matching for the middleware exemptions (mcp, _meta, geocode,
 * OAuth discovery). Every emitted route ends in a `[/{a}[/{params:.*}]]`
 * catch-all, so an exemption tested as a SUBSTRING of the path is reachable
 * from any model route: `api/v1/Client/1/api/v1/mcp` used to be marked
 * rbac_public and served the row with no authentication (review 2026-09-23).
 * Match on the path relative to _SUB_DIR_URL, anchored at both ends.
 */
final class RoutePath
{
    public static function relative(string $path, ?string $subDir = null): string
    {
        $subDir ??= defined('_SUB_DIR_URL') ? (string) _SUB_DIR_URL : '/';
        if ($subDir !== '' && str_starts_with($path, $subDir)) {
            return substr($path, strlen($subDir));
        }
        return ltrim($path, '/');
    }

    public static function isMcp(string $path, ?string $subDir = null): bool
    {
        return (bool) preg_match('#^api/v[0-9]+/mcp/?$#', self::relative($path, $subDir));
    }

    public static function isMeta(string $path, ?string $subDir = null): bool
    {
        return (bool) preg_match('#^api/v[0-9]+/_meta/?$#', self::relative($path, $subDir));
    }

    public static function isGeo(string $path, ?string $subDir = null): bool
    {
        return (bool) preg_match('#^api/v[0-9]+/ApiGoat/(geocode|reverseGeocode)/?$#i', self::relative($path, $subDir));
    }

    /**
     * The two RFC 8414 / 9728 documents, served both under the app sub-dir and
     * at the host root (with_mcp rewrites the origin-level URLs into the app).
     */
    public static function isOAuthDiscovery(string $path, ?string $subDir = null): bool
    {
        $re = '#^\.well-known/oauth-(authorization-server|protected-resource)/?$#';
        return (bool) preg_match($re, self::relative($path, $subDir))
            || (bool) preg_match($re, ltrim($path, '/'));
    }
}
