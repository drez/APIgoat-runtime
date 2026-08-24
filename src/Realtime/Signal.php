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
 * It uses ext-sockets' socket_sendto() rather than stream_socket_client('udg://'),
 * deliberately. The stream transport must CONNECT, which gives the handle
 * lifecycle state we do not want: under OpenSwoole's coroutine runtime hooks a
 * hooked unix-dgram stream unlinks the peer path when it is closed, which
 * deletes the sidecar's own socket out from under it. socket_sendto() names the
 * destination on every send and owns no connection, so there is nothing to
 * close and nothing to clean up. ext-sockets is not in composer.json, so a
 * stream fallback remains for hosts without it.
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
    /** @var \Socket|resource|false|null null = not yet resolved, false = unavailable this request */
    private static $sock = null;

    /** true when self::$sock is an ext-sockets socket (sendto), false when a stream (fwrite). */
    private static bool $useSendto = false;

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
            // A dgram send to a socket whose reader has gone away fails rather
            // than blocking; memoise the failure so a burst of writes in one
            // request does not retry per row.
            $ok = self::$useSendto
                ? @\socket_sendto($sock, $payload, \strlen($payload), 0, self::socketPath()) !== false
                : @\fwrite($sock, $payload) !== false;
            if (!$ok) {
                self::close();
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

    /** @return \Socket|resource|false */
    private static function socket()
    {
        if (self::$sock !== null) {
            return self::$sock;
        }
        $path = self::socketPath();
        if (!\file_exists($path)) {
            return self::$sock = false; // sidecar not running
        }

        if (\function_exists('socket_create')) {
            $sock = @\socket_create(AF_UNIX, SOCK_DGRAM, 0);
            if ($sock !== false) {
                @\socket_set_nonblock($sock);
                self::$useSendto = true;
                return self::$sock = $sock;
            }
        }

        $sock = @\stream_socket_client('udg://' . $path, $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
        if ($sock === false) {
            return self::$sock = false;
        }
        \stream_set_blocking($sock, false);
        self::$useSendto = false;
        return self::$sock = $sock;
    }

    private static function close(): void
    {
        if (self::$useSendto) {
            if (self::$sock instanceof \Socket) {
                @\socket_close(self::$sock);
            }
        } elseif (\is_resource(self::$sock)) {
            @\fclose(self::$sock);
        }
    }

    /** Test seam: drop memoised state so a test can flip the knobs. */
    public static function reset(): void
    {
        self::close();
        self::$sock = null;
        self::$useSendto = false;
        self::$enabled = null;
    }
}
