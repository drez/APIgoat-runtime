<?php

namespace ApiGoat\Auth;

use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\TableVersion;

/**
 * Native app -> web session handoff. The mobile app (OAuth bearer, no
 * cookies) opens a web admin page — a dashboard — in a WebView:
 *
 *   1. POST api/v1/WebHandoff/mint {path}   (bearer)  -> {url}
 *   2. WebView GET WebHandoff/open?c=<code>  (no auth) -> web session for the
 *      same user, 303 to <path>
 *
 * The code is 256 random bits, lives TTL seconds, is stored only as its
 * sha256 (APCu, namespaced per project) and is claimed with an atomic
 * delete, so it works exactly once. It is bound to one user and one plain
 * internal path (validPath: no scheme, no //, no .., not auth/oauth/handoff
 * routes). Without a shared store (no APCu) minting is refused: a
 * per-process array would lose the code between the two requests.
 */
final class WebHandoff
{
    public const TTL = 60;

    /** @var array<string,array>|null test seam: in-memory store */
    private static ?array $store = null;
    private static ?bool $shared = null;

    public static function validPath(string $path): bool
    {
        if (!\preg_match('#^[A-Za-z][A-Za-z0-9_-]*(/[A-Za-z0-9_-]+){0,4}(\?[A-Za-z0-9_=&%.-]*)?$#', $path)) {
            return false;
        }
        $head = \strtolower(\explode('/', $path, 2)[0]);
        return !\in_array($head, ['authy', 'oauth', 'webhandoff', 'api'], true);
    }

    /** A new code for $idAuthy -> $path, or null when refused. */
    public static function mint(int $idAuthy, string $path, ?int $now = null): ?string
    {
        if ($idAuthy <= 0 || !self::validPath($path) || !self::sharedStore()) {
            return null;
        }
        $code = \bin2hex(\random_bytes(32));
        self::put(self::key($code), ['id_authy' => $idAuthy, 'path' => $path, 'exp' => ($now ?? \time()) + self::TTL]);
        return $code;
    }

    /** @return array{id_authy:int,path:string}|null the claim, once; null when unknown, used or expired */
    public static function consume(string $code, ?int $now = null): ?array
    {
        if (!\preg_match('/^[0-9a-f]{64}$/', $code)) {
            return null;
        }
        $v = self::take(self::key($code));
        if (!\is_array($v) || ($v['exp'] ?? 0) < ($now ?? \time())) {
            return null;
        }
        return ['id_authy' => (int) $v['id_authy'], 'path' => (string) $v['path']];
    }

    private static function key(string $code): string
    {
        return 'gc:handoff:' . TableVersion::ns() . ':' . \hash('sha256', $code);
    }

    private static function sharedStore(): bool
    {
        return self::$store !== null || (self::$shared ?? MicroCache::shared());
    }

    private static function put(string $key, array $val): void
    {
        if (self::$store !== null) {
            self::$store[$key] = $val;
            return;
        }
        \apcu_store($key, $val, self::TTL);
    }

    /** Atomic claim: only the caller whose delete succeeds gets the value. */
    private static function take(string $key): ?array
    {
        if (self::$store !== null) {
            $v = self::$store[$key] ?? null;
            unset(self::$store[$key]);
            return $v;
        }
        if (!self::sharedStore()) {
            return null;
        }
        $v = \apcu_fetch($key, $ok);
        if (!$ok || !\apcu_delete($key)) {
            return null;
        }
        return \is_array($v) ? $v : null;
    }

    /** Test seam: an in-memory store ([]), or null for APCu. */
    public static function useStore(?array $store): void
    {
        self::$store = $store;
    }

    /** Test seam: force the shared-store check (null = MicroCache::shared()). */
    public static function forceShared(?bool $shared): void
    {
        self::$shared = $shared;
    }
}
