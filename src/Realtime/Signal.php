<?php

namespace ApiGoat\Realtime;

/**
 * Fire-and-forget change signal from the FPM app to the realtime sidecar.
 *
 * Called from TableVersion::bump() — i.e. on EVERY ORM write in EVERY project —
 * so the disabled path must cost essentially nothing and NO path may ever throw.
 * A write must never fail because a notification could not be delivered.
 *
 * Transport is a Unix SOCK_DGRAM socket, not APCu: APCu shared memory is
 * per-pool, and the sidecar is a separate CLI process that cannot see the FPM
 * pool's segment. A datagram write is connectionless (no handshake, no
 * blocking) and is silently discarded when nobody is listening — exactly the
 * required semantics.
 *
 * Knobs (project .env):
 *   GC_RT_ENABLED  0/1   master switch, default 0 (inert)
 *   GC_RT_SOCK     path  socket path, relative to _BASE_DIR; default tmp/rt.sock
 *
 * The payload carries NO row data — only the table name, its new generation
 * token and the writer's tenant token. Clients react by re-fetching through the
 * normal API, where RBAC/ACL/tenant scoping applies unchanged. The sidecar
 * therefore holds no authorization logic and can leak nothing.
 */
final class Signal
{
    /** @var resource|false|null null = not yet resolved, false = unavailable this request */
    private static $sock = null;

    private static ?bool $enabled = null;

    /** Best-effort notify. Never throws, never warns, never blocks. */
    public static function emit(string $table, string $gen = '', string $tenant = 'all'): void
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $sock = self::socket();
            if ($sock === false) {
                return;
            }
            $payload = \json_encode(['t' => $table, 'g' => $gen, 'tn' => $tenant]);
            if ($payload === false) {
                return;
            }
            // A dgram write to a socket whose reader has gone away fails rather
            // than blocking; memoise the failure so a burst of writes in one
            // request does not retry per row.
            if (@\fwrite($sock, $payload) === false) {
                @\fclose($sock);
                self::$sock = false;
            }
        } catch (\Throwable $e) {
            self::$sock = false; // stop trying for the rest of this request
        }
    }

    /** GC_RT_ENABLED — default off, so the fleet is unaffected until opted in. */
    public static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        $v = \function_exists('env') ? env('GC_RT_ENABLED') : \getenv('GC_RT_ENABLED');
        return self::$enabled = \in_array(\strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    /** Absolute socket path. Relative GC_RT_SOCK values resolve against _BASE_DIR. */
    public static function socketPath(): string
    {
        $v = \function_exists('env') ? env('GC_RT_SOCK') : \getenv('GC_RT_SOCK');
        $v = \is_string($v) && $v !== '' ? $v : 'tmp/rt.sock';
        if ($v[0] === DIRECTORY_SEPARATOR) {
            return $v;
        }
        $base = \defined('_BASE_DIR') ? \rtrim((string) \_BASE_DIR, DIRECTORY_SEPARATOR) : (string) \getcwd();
        return $base . DIRECTORY_SEPARATOR . $v;
    }

    /** @return resource|false */
    private static function socket()
    {
        if (self::$sock !== null) {
            return self::$sock;
        }
        $path = self::socketPath();
        if (!\file_exists($path)) {
            return self::$sock = false; // sidecar not running
        }
        $sock = @\stream_socket_client('udg://' . $path, $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
        if ($sock === false) {
            return self::$sock = false;
        }
        \stream_set_blocking($sock, false);
        return self::$sock = $sock;
    }

    /** Test seam: drop memoised state so a test can flip the knobs. */
    public static function reset(): void
    {
        if (\is_resource(self::$sock)) {
            @\fclose(self::$sock);
        }
        self::$sock = null;
        self::$enabled = null;
    }
}
