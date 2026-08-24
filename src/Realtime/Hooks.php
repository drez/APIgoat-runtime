<?php

namespace ApiGoat\Realtime;

/**
 * Opt-in project extension point for the realtime sidecar.
 *
 * A project may define `App\Domains\RealtimeHandler` (in `src/App/Domains/`,
 * which `gc build` never overwrites). If the class exists, the sidecar calls
 * whichever of the hooks below it implements. If it does not exist — the normal
 * case — nothing is loaded and nothing changes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * READ THIS BEFORE WRITING A HANDLER
 *
 * The sidecar is a SINGLE long-lived worker running an event loop. Code here is
 * not like code in a request:
 *
 *   - **Never block.** No database queries, no HTTP calls, no sleep(), no file
 *     locks. A 200ms blocking call stalls EVERY connected client for 200ms.
 *   - **Never accumulate.** The process runs for days. A growing static array is
 *     a leak, not a cache.
 *   - **Never push row data.** The whole security model is that the socket
 *     carries table names only, so clients must re-fetch through the normal API
 *     where RBAC/ACL/tenant scoping applies. A hook that pushes record contents
 *     bypasses every authorization check in the application.
 *   - **Assume no session.** There is no $_SESSION, no request, no Authy. The
 *     only identity available is the verified ticket claims.
 *
 * A hook that throws is caught and logged. A hook that throws repeatedly is
 * DISABLED for the life of the process (see self::FAILURE_LIMIT) — a broken
 * handler degrades to no handler rather than taking the sidecar down.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Every hook is optional. Signatures:
 *
 *   onStart(): void
 *       Once, after the server is listening.
 *
 *   onOpen(int $fd, array $claims): void
 *       A client authenticated. $claims = ['u' => authy id, 'tn' => tenant token].
 *
 *   allowSubscribe(string $table, array $claims): bool
 *       Extra gate on a subscription. Return false to refuse it. Note this is a
 *       CONVENIENCE, not the authorization boundary: a table name is not data,
 *       and the re-fetch is still fully authorized.
 *
 *   onMessage(object $server, int $fd, array $msg, array $claims): bool
 *       Custom client ops. Return true when handled, so the built-in
 *       sub/unsub/ping handling is skipped.
 *
 *   allowPush(string $table, string $tenant, array $client): ?bool
 *       Override the default tenant-match rule for one client. Return null to
 *       keep the default.
 *
 *   onClose(int $fd): void
 *       A client went away. Drop anything you keyed on $fd.
 */
final class Hooks
{
    /** Project-owned handler class. `Domains/` is the "yours" layer; never overwritten by a build. */
    public const HANDLER = '\\App\\Domains\\RealtimeHandler';

    /** Consecutive throws before a hook is switched off for this process. */
    private const FAILURE_LIMIT = 10;

    private static ?bool $present = null;

    /** @var array<string,int> hook name -> consecutive failures */
    private static array $failures = [];

    /** @var callable(string):void */
    private static $logger;

    public static function setLogger(callable $logger): void
    {
        self::$logger = $logger;
    }

    /** Does this project ship a handler at all? */
    public static function present(): bool
    {
        if (self::$present !== null) {
            return self::$present;
        }
        return self::$present = \class_exists(self::HANDLER);
    }

    /**
     * Call a hook if the project implements it.
     *
     * @param list<mixed> $args
     * @return mixed the hook's return value, or $default when absent/disabled/throwing
     */
    public static function call(string $hook, array $args, mixed $default = null): mixed
    {
        if (!self::present()) {
            return $default;
        }
        if ((self::$failures[$hook] ?? 0) >= self::FAILURE_LIMIT) {
            return $default; // switched off after repeated throws
        }
        if (!\method_exists(self::HANDLER, $hook)) {
            return $default;
        }

        try {
            $out = \call_user_func_array([self::HANDLER, $hook], $args);
            self::$failures[$hook] = 0;
            return $out;
        } catch (\Throwable $e) {
            $n = (self::$failures[$hook] = (self::$failures[$hook] ?? 0) + 1);
            self::log("hook {$hook} threw ({$n}/" . self::FAILURE_LIMIT . '): ' . $e->getMessage());
            if ($n >= self::FAILURE_LIMIT) {
                self::log("hook {$hook} DISABLED for this process — restart the sidecar after fixing it");
            }
            return $default;
        }
    }

    private static function log(string $line): void
    {
        if (self::$logger !== null) {
            (self::$logger)('realtime-hook: ' . $line);
        }
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$present = null;
        self::$failures = [];
    }
}
