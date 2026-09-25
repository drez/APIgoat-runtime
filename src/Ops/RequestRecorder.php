<?php

namespace ApiGoat\Ops;

/**
 * Records one HTTP request into the ops_* telemetry tables emitted by the
 * with_ops_monitor behavior: an hourly latency rollup (ops_req_hour) plus a
 * raw row for anything slow or 5xx (ops_req_slow).
 *
 * Never allowed to break the request it is recording — every public entry
 * point but the pure helpers (bucket/routeKey/currentAuthyId, which cannot
 * throw) is either safeRecord() itself or something that calls it from a
 * try/catch. Failures go to error_log('[ops] ...') and are dropped.
 *
 * MySQL only (R1): this host's test runner has no pdo_sqlite, and the
 * project databases are always MySQL, so record() speaks
 * `INSERT ... ON DUPLICATE KEY UPDATE` and nothing else.
 */
final class RequestRecorder
{
    /** Upper bound (inclusive) of each latency bucket, in ms; the last one catches everything above. */
    private const BUCKET_BOUNDS = [
        'b100'  => 100,
        'b250'  => 250,
        'b500'  => 500,
        'b1000' => 1000,
        'b2500' => 2500,
    ];

    /** The only column names bucket() can produce — never build SQL from an unwhitelisted name. */
    private const BUCKET_COLUMNS = ['b100', 'b250', 'b500', 'b1000', 'b2500', 'b_inf'];

    /**
     * Work queued for the shutdown callback, in registration order. defer()
     * pushes this request's record here; Task 3 adds its slow-query buffer
     * flush the same way (another closure pushed onto this array), so both
     * run from the single shutdown function registered below.
     *
     * @var list<callable>
     */
    private static array $shutdownHooks = [];

    private static bool $shutdownRegistered = false;

    /**
     * The matched route pattern of the request in flight, as noted from
     * INSIDE Slim's routing (see noteRoute()). ServerTimingMiddleware — the
     * outermost middleware — cannot read it itself: Slim's RoutingMiddleware
     * puts the routing attributes only on the NEW request object it hands to
     * the inner middlewares, never on the one the outer ones hold, so
     * RouteContext::fromRequest() on the outer request always throws.
     */
    private static ?string $notedRoute = null;

    /**
     * Test seam: the records defer() queued and that have not been run yet,
     * in order. Emptied together with the hooks at shutdown and by
     * takePendingForTest().
     *
     * @var list<array<string,mixed>>
     */
    private static array $pending = [];

    /** Start of a request (ServerTimingMiddleware): forget the previous request's route. */
    public static function beginRequest(): void
    {
        self::$notedRoute = null;
    }

    /**
     * Called from inside routing (SessionReleaseMiddleware, the first runtime
     * middleware after RoutingMiddleware) with the matched route's pattern.
     * Requests that never reach routing — short-circuited by Authy/Rbac/Jwt
     * above it, or 404/405 — are never noted and keep '(unmatched)'.
     */
    public static function noteRoute(?string $pattern): void
    {
        self::$notedRoute = ($pattern === null || $pattern === '') ? null : $pattern;
    }

    /** The pattern noteRoute() recorded for the request in flight, or null. */
    public static function notedRoute(): ?string
    {
        return self::$notedRoute;
    }

    /**
     * Test seam: return the records defer() queued and drop them together
     * with their shutdown hooks, so nothing tries a real database when the
     * test process exits.
     *
     * @return list<array<string,mixed>>
     */
    public static function takePendingForTest(): array
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$shutdownHooks = [];

        return $pending;
    }

    /**
     * Which of the fixed latency buckets $ms falls into.
     *
     * @return 'b100'|'b250'|'b500'|'b1000'|'b2500'|'b_inf'
     */
    public static function bucket(int $ms): string
    {
        foreach (self::BUCKET_BOUNDS as $name => $bound) {
            if ($ms <= $bound) {
                return $name;
            }
        }

        return 'b_inf';
    }

    /**
     * The route's dedup key for ops_req_hour: the matched pattern, or the
     * fixed '(unmatched)' marker when routing never resolved one. Capped at
     * 191 chars — the column's width — so a pathological pattern can never
     * blow the insert.
     */
    public static function routeKey(?string $pattern): string
    {
        if ($pattern === null || $pattern === '') {
            return '(unmatched)';
        }

        return \substr($pattern, 0, 191);
    }

    /**
     * Upsert the hourly rollup and, when this request is slow or a 5xx,
     * insert the raw ops_req_slow row. Never call this directly from
     * request-handling code — go through safeRecord() (or defer(), which
     * calls safeRecord() at shutdown).
     *
     * $r = [route, method, path, status, ms, queries, id_authy, ip, ts]
     */
    public static function record(\PDO $pdo, array $r, int $slowMs): void
    {
        $hour   = \intdiv((int) $r['ts'], 3600) * 3600;
        $route  = \substr((string) $r['route'], 0, 191);
        $method = \substr((string) $r['method'], 0, 8);
        $ms     = (int) $r['ms'];
        $status = (int) $r['status'];
        $is4xx  = ($status >= 400 && $status < 500) ? 1 : 0;
        $is5xx  = ($status >= 500) ? 1 : 0;

        $bucket = self::bucket($ms);
        if (!\in_array($bucket, self::BUCKET_COLUMNS, true)) {
            // bucket() cannot return anything else; defensive only.
            $bucket = 'b_inf';
        }

        // Every bucket column is set explicitly on INSERT (5 zeros + 1 one),
        // never left out: SPEC declares them nullable, and an omitted column
        // that later hits "col = col + 1" on UPDATE would add 1 to NULL —
        // which MySQL evaluates to NULL, silently losing every future
        // increment of that bucket for this (hour,route,method) row.
        $cols = self::BUCKET_COLUMNS;
        $bucketList = \implode(', ', $cols);
        $bucketPlaceholders = \implode(', ', \array_map(static fn ($c) => ":{$c}", $cols));

        $sql = "INSERT INTO ops_req_hour (hour, route, method, n, sum_ms, max_ms, n_4xx, n_5xx, {$bucketList}, created_at)
                VALUES (:hour, :route, :method, 1, :ms, :ms, :n4xx, :n5xx, {$bucketPlaceholders}, :created_at)
                ON DUPLICATE KEY UPDATE
                    n = n + 1,
                    sum_ms = sum_ms + VALUES(sum_ms),
                    max_ms = GREATEST(max_ms, VALUES(max_ms)),
                    n_4xx = n_4xx + VALUES(n_4xx),
                    n_5xx = n_5xx + VALUES(n_5xx),
                    {$bucket} = {$bucket} + 1";

        $params = [
            ':hour'       => $hour,
            ':route'      => $route,
            ':method'     => $method,
            ':ms'         => $ms,
            ':n4xx'       => $is4xx,
            ':n5xx'       => $is5xx,
            ':created_at' => (int) $r['ts'],
        ];
        foreach ($cols as $c) {
            $params[":{$c}"] = ($c === $bucket) ? 1 : 0;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($ms >= $slowMs || $status >= 500) {
            $idAuthy = $r['id_authy'] ?? null;
            $slowStmt = $pdo->prepare(
                'INSERT INTO ops_req_slow (route, method, path, status, ms, queries, id_authy, ip, created_at)
                 VALUES (:route, :method, :path, :status, :ms, :queries, :id_authy, :ip, :created_at)'
            );
            $slowStmt->execute([
                ':route'      => $route,
                ':method'     => $method,
                ':path'       => self::stripQueryString((string) ($r['path'] ?? '')),
                ':status'     => $status,
                ':ms'         => $ms,
                ':queries'    => (int) ($r['queries'] ?? 0),
                ':id_authy'   => ($idAuthy === null || $idAuthy === '') ? null : (int) $idAuthy,
                ':ip'         => \substr((string) ($r['ip'] ?? ''), 0, 45),
                ':created_at' => (int) $r['ts'],
            ]);
        }
    }

    /**
     * record(), but a DB failure never escapes: it goes to error_log instead.
     * This is the entry point every caller (including the shutdown hook
     * defer() registers) should use.
     */
    public static function safeRecord(\PDO $pdo, array $r, int $slowMs): void
    {
        try {
            self::record($pdo, $r, $slowMs);
        } catch (\Throwable $e) {
            \error_log('[ops] record failed: ' . $e->getMessage());
        }
    }

    /**
     * Queue $r to be recorded after the response has been sent. Registers
     * (once) a shutdown function that first releases the client via
     * fastcgi_finish_request() when available, then runs every queued hook
     * — this request's record, plus (Task 3) the slow-query buffer flush,
     * keyed to the same routeKey this request's record uses. Getting the DB
     * connection happens inside each hook's own try, so a Propel/connection
     * problem at shutdown can never throw.
     */
    public static function defer(array $r): void
    {
        self::$pending[] = $r;

        self::$shutdownHooks[] = static function () use ($r): void {
            try {
                $pdo = \Propel::getConnection(_DATA_SRC);
                self::safeRecord($pdo, $r, (int) Config::get('slow_ms'));
            } catch (\Throwable $e) {
                \error_log('[ops] record failed: ' . $e->getMessage());
            }
        };

        self::$shutdownHooks[] = static function () use ($r): void {
            try {
                $pdo = \Propel::getConnection(_DATA_SRC);
                SlowQueryBuffer::flush($pdo, (string) $r['route']);
            } catch (\Throwable $e) {
                \error_log('[ops] slow query flush failed: ' . $e->getMessage());
            }
        };

        self::registerShutdown();
    }

    /**
     * $_SESSION[_AUTH_VAR]->getIdAuthy(), read as defensively as every other
     * RT call site touching this session (see ApiGoat\Auth\AccountSecurity):
     * _AUTH_VAR may be undefined, the session slot unset, or the object not
     * the session class at all (e.g. mid-login, or a non-web entry point
     * such as a cron run with no session at all).
     */
    public static function currentAuthyId(): ?int
    {
        try {
            if (!\defined('_AUTH_VAR') || !isset($_SESSION[\_AUTH_VAR]) || !\is_object($_SESSION[\_AUTH_VAR])) {
                return null;
            }
            $session = $_SESSION[\_AUTH_VAR];
            if (!\method_exists($session, 'getIdAuthy')) {
                return null;
            }
            $id = $session->getIdAuthy();

            return ($id === null || $id === '') ? null : (int) $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Query string stripped, truncated to the ops_req_slow.path column width. */
    private static function stripQueryString(string $path): string
    {
        $stripped = \explode('?', $path, 2)[0];

        return \substr($stripped, 0, 255);
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        try {
            \register_shutdown_function(static function (): void {
                try {
                    if (\function_exists('fastcgi_finish_request')) {
                        \fastcgi_finish_request();
                    }
                } catch (\Throwable $e) {
                    // the client connection is irrelevant to whether we can still record
                }

                $hooks = self::$shutdownHooks;
                self::$shutdownHooks = [];
                self::$pending = [];
                self::$shutdownRegistered = false;

                foreach ($hooks as $hook) {
                    try {
                        $hook();
                    } catch (\Throwable $e) {
                        \error_log('[ops] shutdown hook failed: ' . $e->getMessage());
                    }
                }
            });
        } catch (\Throwable $e) {
            self::$shutdownRegistered = false;
        }
    }
}
