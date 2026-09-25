<?php

namespace ApiGoat\Ops;

/**
 * Read-only query layer over the ops_* telemetry tables (emitted by
 * with_ops_monitor, Task 1) plus the framework's own authy_log / api_rbac /
 * oauth_client / oauth_access_token tables — the engine behind the Security
 * & Performance dashboards and the ops_* MCP tools (Task 7).
 *
 * Every range is [int $from, int $to] in unix seconds, inclusive. All
 * aggregation happens in SQL (COUNT/SUM/GROUP BY) — ops_sec_event in
 * particular can be large (a deny flood), so nothing here lists rows into
 * PHP just to count or sum them.
 *
 * api_rbac.rule and api_rbac.method are GoatCheese ENUM columns stored as
 * TINYINT ordinals. api_rbac is a FIXED base table the emitter defines once
 * for every project (vendor/apigoat/goatcheese/Model/ApiSupportDb.php,
 * getColumnsDefinitions_api_rbac) — valueSet "Allow, Deny" for rule and
 * "GET, POST, PATCH, PUT, DELETE, ALL" for method, in that literal order —
 * so the ordinal->label mapping below is safe to hardcode rather than
 * requiring a generated Peer class (Stats has no dependency on \App\*).
 */
final class Stats
{
    /** api_rbac.rule ordinal for 'Deny' (valueSet "Allow, Deny": Allow=0, Deny=1). */
    private const RULE_DENY = 1;

    /** api_rbac.method ordinals, in the emitter's fixed valueSet order. */
    private const METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'ALL'];

    /** ops_req_hour histogram bucket columns => their upper bound (ms). */
    private const BUCKET_BOUNDS = ['b100' => 100, 'b250' => 250, 'b500' => 500, 'b1000' => 1000, 'b2500' => 2500];

    public function __construct(private \PDO $pdo)
    {
    }

    // ── Security ─────────────────────────────────────────────────────────

    /**
     * @return array{failed_logins:int, ok_logins:int, rbac_denies:int, sec_events:int, active_tokens:int}
     */
    public function securityOverview(int $from, int $to): array
    {
        $logins = $this->one(
            "SELECT
                COALESCE(SUM(CASE WHEN result = 'w' THEN count ELSE 0 END), 0) failed,
                COALESCE(SUM(CASE WHEN result = 'g' THEN count ELSE 0 END), 0) ok
             FROM authy_log
             WHERE UNIX_TIMESTAMP(timestamp) BETWEEN ? AND ?",
            [$from, $to]
        ) ?? ['failed' => 0, 'ok' => 0];

        $rbacDenies = (int) $this->scalar(
            'SELECT COUNT(*) FROM ops_sec_event WHERE type = ? AND created_at BETWEEN ? AND ?',
            ['rbac_deny', $from, $to]
        );
        $secEvents = (int) $this->scalar(
            'SELECT COUNT(*) FROM ops_sec_event WHERE created_at BETWEEN ? AND ?',
            [$from, $to]
        );
        // "Active" is a right-now snapshot (currently valid, unrevoked
        // tokens), independent of the reporting range — a security overview
        // for last month should still show today's real exposure.
        $activeTokens = (int) $this->scalar(
            'SELECT COUNT(*) FROM oauth_access_token WHERE revoked = 0 AND expires > ?',
            [\time()]
        );

        return [
            'failed_logins' => (int) $logins['failed'],
            'ok_logins'     => (int) $logins['ok'],
            'rbac_denies'   => $rbacDenies,
            'sec_events'    => $secEvents,
            'active_tokens' => $activeTokens,
        ];
    }

    /**
     * Days are DATE(timestamp) in the MySQL session's time zone (see
     * latencyTrend() for the UTC-day contrast).
     *
     * @return list<array{day:string, failed:int, ok:int}>
     */
    public function loginTrend(int $from, int $to): array
    {
        $rows = $this->all(
            "SELECT DATE(timestamp) day,
                    COALESCE(SUM(CASE WHEN result = 'w' THEN count ELSE 0 END), 0) failed,
                    COALESCE(SUM(CASE WHEN result = 'g' THEN count ELSE 0 END), 0) ok
             FROM authy_log
             WHERE UNIX_TIMESTAMP(timestamp) BETWEEN ? AND ?
             GROUP BY day
             ORDER BY day",
            [$from, $to]
        );

        return \array_map(static fn (array $r) => [
            'day'    => (string) $r['day'],
            'failed' => (int) $r['failed'],
            'ok'     => (int) $r['ok'],
        ], $rows);
    }

    /** @return list<array{ip:string, login:string, failures:int}> */
    public function topFailing(int $from, int $to, int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT ip, login, COALESCE(SUM(count), 0) failures
             FROM authy_log
             WHERE result = ? AND UNIX_TIMESTAMP(timestamp) BETWEEN ? AND ?
             GROUP BY ip, login
             ORDER BY failures DESC
             LIMIT ' . $limit,
            ['w', $from, $to]
        );

        return \array_map(static fn (array $r) => [
            'ip'       => (string) $r['ip'],
            'login'    => (string) $r['login'],
            'failures' => (int) $r['failures'],
        ], $rows);
    }

    /** @return list<array{type:string, id_authy:?int, ip:?string, detail:string, created_at:int}> */
    public function secEvents(int $from, int $to, ?string $type, int $limit): array
    {
        $limit = self::clampLimit($limit);
        $sql = 'SELECT type, id_authy, ip, detail, created_at
                FROM ops_sec_event
                WHERE created_at BETWEEN ? AND ?';
        $args = [$from, $to];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $args[] = $type;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

        $rows = $this->all($sql, $args);

        return \array_map(static fn (array $r) => [
            'type'       => (string) $r['type'],
            'id_authy'   => $r['id_authy'] !== null ? (int) $r['id_authy'] : null,
            'ip'         => $r['ip'] !== null ? (string) $r['ip'] : null,
            'detail'     => (string) $r['detail'],
            'created_at' => (int) $r['created_at'],
        ], $rows);
    }

    /** Type => count, over the range — for the tools/dashboard breakdown. @return array<string,int> */
    public function secEventCounts(int $from, int $to): array
    {
        $rows = $this->all(
            'SELECT type, COUNT(*) n FROM ops_sec_event WHERE created_at BETWEEN ? AND ? GROUP BY type',
            [$from, $to]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['type']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * All-time Deny rules (api_rbac.count is a cumulative used-count, not a
     * ranged one — unlike secEvents()/secEventCounts(), which are ranged).
     *
     * @return list<array{model:string, action:?string, method:string, count:int, date_modification:?string}>
     */
    public function denyRoutes(int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT model, action, method, count, date_modification
             FROM api_rbac
             WHERE rule = ?
             ORDER BY count DESC
             LIMIT ' . $limit,
            [self::RULE_DENY]
        );

        return \array_map(static fn (array $r) => [
            'model'             => (string) $r['model'],
            'action'            => $r['action'] !== null ? (string) $r['action'] : null,
            'method'            => self::METHODS[(int) $r['method']] ?? (string) $r['method'],
            'count'             => (int) $r['count'],
            'date_modification' => $r['date_modification'] !== null ? (string) $r['date_modification'] : null,
        ], $rows);
    }

    /** @return list<array{client_id:string, created_at:?string, active_tokens:int}> */
    public function oauthClients(): array
    {
        $rows = $this->all(
            'SELECT c.client_id, c.created_at,
                    COUNT(t.id_oauth_access_token) active_tokens
             FROM oauth_client c
             LEFT JOIN oauth_access_token t
                    ON t.client_id = c.client_id AND t.revoked = 0 AND t.expires > ?
             GROUP BY c.id_oauth_client, c.client_id, c.created_at
             ORDER BY c.created_at DESC',
            [\time()]
        );

        return \array_map(static fn (array $r) => [
            'client_id'     => (string) $r['client_id'],
            'created_at'    => $r['created_at'] !== null ? (string) $r['created_at'] : null,
            'active_tokens' => (int) $r['active_tokens'],
        ], $rows);
    }

    // ── Performance ──────────────────────────────────────────────────────

    /** @return array{requests:int, avg_ms:float, p95_ms:int, rate_5xx:float, slow_queries:int} */
    public function perfOverview(int $from, int $to): array
    {
        $row = $this->one(
            'SELECT
                COALESCE(SUM(n), 0) n,
                COALESCE(SUM(sum_ms), 0) sum_ms,
                COALESCE(SUM(n_5xx), 0) n_5xx,
                COALESCE(MAX(max_ms), 0) max_ms,
                COALESCE(SUM(b100), 0) b100, COALESCE(SUM(b250), 0) b250, COALESCE(SUM(b500), 0) b500,
                COALESCE(SUM(b1000), 0) b1000, COALESCE(SUM(b2500), 0) b2500, COALESCE(SUM(b_inf), 0) b_inf
             FROM ops_req_hour
             WHERE hour BETWEEN ? AND ?',
            [$from, $to]
        );

        $requests = $row !== null ? (int) $row['n'] : 0;
        $sumMs = $row !== null ? (int) $row['sum_ms'] : 0;
        $n5xx = $row !== null ? (int) $row['n_5xx'] : 0;

        $slowQueries = (int) $this->scalar(
            'SELECT COUNT(*) FROM ops_query_slow WHERE created_at BETWEEN ? AND ?',
            [$from, $to]
        );

        return [
            'requests'     => $requests,
            'avg_ms'       => $requests > 0 ? \round($sumMs / $requests, 1) : 0.0,
            'p95_ms'       => $row !== null ? self::p95FromBuckets(self::bucketsFromRow($row)) : 0,
            'rate_5xx'     => $requests > 0 ? \round($n5xx / $requests, 4) : 0.0,
            'slow_queries' => $slowQueries,
        ];
    }

    /**
     * Grouped by hour for a range up to 3 days, by day for anything longer.
     *
     * Day buckets are UTC days (FLOOR(unix hour / 86400)), whereas
     * loginTrend() groups by DATE(timestamp) in the MySQL session's time
     * zone — the two trends' day boundaries can differ by the UTC offset.
     *
     * @return list<array{hour:int, n:int, avg_ms:float, p95_ms:int}>
     */
    public function latencyTrend(int $from, int $to): array
    {
        $byDay = ($to - $from) > 3 * 86400;
        $bucketExpr = $byDay ? 'FLOOR(hour / 86400) * 86400' : 'hour';

        $rows = $this->all(
            "SELECT {$bucketExpr} bucket,
                    COALESCE(SUM(n), 0) n,
                    COALESCE(SUM(sum_ms), 0) sum_ms,
                    COALESCE(MAX(max_ms), 0) max_ms,
                    COALESCE(SUM(b100), 0) b100, COALESCE(SUM(b250), 0) b250, COALESCE(SUM(b500), 0) b500,
                    COALESCE(SUM(b1000), 0) b1000, COALESCE(SUM(b2500), 0) b2500, COALESCE(SUM(b_inf), 0) b_inf
             FROM ops_req_hour
             WHERE hour BETWEEN ? AND ?
             GROUP BY bucket
             ORDER BY bucket",
            [$from, $to]
        );

        return \array_map(static function (array $r) {
            $n = (int) $r['n'];

            return [
                'hour'   => (int) $r['bucket'],
                'n'      => $n,
                'avg_ms' => $n > 0 ? \round(((int) $r['sum_ms']) / $n, 1) : 0.0,
                'p95_ms' => self::p95FromBuckets(self::bucketsFromRow($r)),
            ];
        }, $rows);
    }

    /** @return list<array{route:string, method:string, n:int, avg_ms:float, p95_ms:int, n_5xx:int}> */
    public function slowestRoutes(int $from, int $to, int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT route, method,
                    COALESCE(SUM(n), 0) n,
                    COALESCE(SUM(sum_ms), 0) sum_ms,
                    COALESCE(MAX(max_ms), 0) max_ms,
                    COALESCE(SUM(n_5xx), 0) n_5xx,
                    COALESCE(SUM(b100), 0) b100, COALESCE(SUM(b250), 0) b250, COALESCE(SUM(b500), 0) b500,
                    COALESCE(SUM(b1000), 0) b1000, COALESCE(SUM(b2500), 0) b2500, COALESCE(SUM(b_inf), 0) b_inf
             FROM ops_req_hour
             WHERE hour BETWEEN ? AND ?
             GROUP BY route, method
             ORDER BY sum_ms DESC
             LIMIT ' . $limit,
            [$from, $to]
        );

        return \array_map(static function (array $r) {
            $n = (int) $r['n'];

            return [
                'route'   => (string) $r['route'],
                'method'  => (string) $r['method'],
                'n'       => $n,
                'avg_ms'  => $n > 0 ? \round(((int) $r['sum_ms']) / $n, 1) : 0.0,
                'p95_ms'  => self::p95FromBuckets(self::bucketsFromRow($r)),
                'n_5xx'   => (int) $r['n_5xx'],
            ];
        }, $rows);
    }

    /** @return list<array{route:?string, method:?string, path:?string, status:?int, ms:int, queries:?int, id_authy:?int, ip:?string, created_at:int}> */
    public function slowRequests(int $from, int $to, int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT route, method, path, status, ms, queries, id_authy, ip, created_at
             FROM ops_req_slow
             WHERE created_at BETWEEN ? AND ?
             ORDER BY created_at DESC
             LIMIT ' . $limit,
            [$from, $to]
        );

        return \array_map(static fn (array $r) => [
            'route'      => $r['route'] !== null ? (string) $r['route'] : null,
            'method'     => $r['method'] !== null ? (string) $r['method'] : null,
            'path'       => $r['path'] !== null ? (string) $r['path'] : null,
            'status'     => $r['status'] !== null ? (int) $r['status'] : null,
            'ms'         => (int) $r['ms'],
            'queries'    => $r['queries'] !== null ? (int) $r['queries'] : null,
            'id_authy'   => $r['id_authy'] !== null ? (int) $r['id_authy'] : null,
            'ip'         => $r['ip'] !== null ? (string) $r['ip'] : null,
            'created_at' => (int) $r['created_at'],
        ], $rows);
    }

    /** @return list<array{route:?string, ms:int, sql_hash:?string, sql_text:?string, created_at:int}> */
    public function slowQueries(int $from, int $to, int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT route, ms, sql_hash, sql_text, created_at
             FROM ops_query_slow
             WHERE created_at BETWEEN ? AND ?
             ORDER BY created_at DESC
             LIMIT ' . $limit,
            [$from, $to]
        );

        return \array_map(static fn (array $r) => [
            'route'      => $r['route'] !== null ? (string) $r['route'] : null,
            'ms'         => (int) $r['ms'],
            'sql_hash'   => $r['sql_hash'] !== null ? (string) $r['sql_hash'] : null,
            'sql_text'   => $r['sql_text'] !== null ? (string) $r['sql_text'] : null,
            'created_at' => (int) $r['created_at'],
        ], $rows);
    }

    /** @return list<array{table:string, rows:int, data_bytes:int, index_bytes:int, total_bytes:int}> */
    public function tableSizes(int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT TABLE_NAME table_name, TABLE_ROWS table_rows,
                    DATA_LENGTH data_length, INDEX_LENGTH index_length,
                    (DATA_LENGTH + INDEX_LENGTH) total_bytes
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY total_bytes DESC
             LIMIT ' . $limit
        );

        return \array_map(static fn (array $r) => [
            'table'       => (string) $r['table_name'],
            'rows'        => $r['table_rows'] !== null ? (int) $r['table_rows'] : 0,
            'data_bytes'  => (int) $r['data_length'],
            'index_bytes' => (int) $r['index_length'],
            'total_bytes' => (int) $r['total_bytes'],
        ], $rows);
    }

    /** @return list<array{job:string, started_at:?int, ms:?int, ok:bool, summary:?string, created_at:int}> */
    public function cronRuns(int $limit): array
    {
        $limit = self::clampLimit($limit);
        $rows = $this->all(
            'SELECT job, started_at, ms, ok, summary, created_at
             FROM ops_cron_run
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );

        return \array_map(static fn (array $r) => [
            'job'        => (string) $r['job'],
            'started_at' => $r['started_at'] !== null ? (int) $r['started_at'] : null,
            'ms'         => $r['ms'] !== null ? (int) $r['ms'] : null,
            'ok'         => (bool) $r['ok'],
            'summary'    => $r['summary'] !== null ? (string) $r['summary'] : null,
            'created_at' => (int) $r['created_at'],
        ], $rows);
    }

    /** Newest ops_server_snap row, decoded, plus 'age_s' (now - created_at). Null when none. */
    public function serverLatest(): ?array
    {
        $row = $this->one(
            'SELECT created_at, load1, mem_pct, disk_pct, services, f2b_banned, f2b, auth
             FROM ops_server_snap
             ORDER BY created_at DESC
             LIMIT 1'
        );
        if ($row === null) {
            return null;
        }

        return [
            'created_at' => (int) $row['created_at'],
            'age_s'      => \time() - (int) $row['created_at'],
            'load1'      => $row['load1'] !== null ? (float) $row['load1'] : null,
            'mem_pct'    => $row['mem_pct'] !== null ? (float) $row['mem_pct'] : null,
            'disk_pct'   => $row['disk_pct'] !== null ? (float) $row['disk_pct'] : null,
            'services'   => self::decodeJson($row['services'] ?? null),
            'f2b_banned' => $row['f2b_banned'] !== null ? (int) $row['f2b_banned'] : null,
            'f2b'        => self::decodeJson($row['f2b'] ?? null),
            'auth'       => self::decodeJson($row['auth'] ?? null),
        ];
    }

    /** @return list<array{created_at:int, load1:?float, mem_pct:?float, disk_pct:?float, f2b_banned:?int}> */
    public function serverTrend(int $from, int $to): array
    {
        $rows = $this->all(
            'SELECT created_at, load1, mem_pct, disk_pct, f2b_banned
             FROM ops_server_snap
             WHERE created_at BETWEEN ? AND ?
             ORDER BY created_at',
            [$from, $to]
        );

        return \array_map(static fn (array $r) => [
            'created_at' => (int) $r['created_at'],
            'load1'      => $r['load1'] !== null ? (float) $r['load1'] : null,
            'mem_pct'    => $r['mem_pct'] !== null ? (float) $r['mem_pct'] : null,
            'disk_pct'   => $r['disk_pct'] !== null ? (float) $r['disk_pct'] : null,
            'f2b_banned' => $r['f2b_banned'] !== null ? (int) $r['f2b_banned'] : null,
        ], $rows);
    }

    // ── Pure ─────────────────────────────────────────────────────────────

    /**
     * Estimated p95 from a summed ops_req_hour histogram: the upper bound
     * (ms) of the first bucket where the running cumulative count reaches
     * 95% of the total. Pure and static so it is unit-testable without a
     * database (RT/tests/Ops/StatsTest.php).
     *
     * When the 95th percentile falls in the b_inf bucket (>2500ms) the exact
     * value is unknowable from a histogram alone — this returns the caller's
     * 'max_ms' (the real observed max, when summed and passed through) as
     * the best available estimate, or the sentinel 2501 ("just above the
     * last known bound, exact value unknown") when no max_ms was supplied.
     * An all-zero (or empty) histogram returns 0.
     *
     * @param array{b100?:int, b250?:int, b500?:int, b1000?:int, b2500?:int, b_inf?:int, max_ms?:int} $b
     */
    public static function p95FromBuckets(array $b): int
    {
        $total = (int) ($b['b_inf'] ?? 0);
        foreach (self::BUCKET_BOUNDS as $key => $bound) {
            $total += (int) ($b[$key] ?? 0);
        }
        if ($total <= 0) {
            return 0;
        }

        $target = $total * 0.95;
        $cumulative = 0;
        foreach (self::BUCKET_BOUNDS as $key => $bound) {
            $cumulative += (int) ($b[$key] ?? 0);
            if ($cumulative >= $target) {
                return $bound;
            }
        }

        $maxMs = (int) ($b['max_ms'] ?? 0);

        return $maxMs > 0 ? $maxMs : 2501;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array{b100:int, b250:int, b500:int, b1000:int, b2500:int, b_inf:int, max_ms:int} */
    private static function bucketsFromRow(array $row): array
    {
        return [
            'b100'   => (int) ($row['b100'] ?? 0),
            'b250'   => (int) ($row['b250'] ?? 0),
            'b500'   => (int) ($row['b500'] ?? 0),
            'b1000'  => (int) ($row['b1000'] ?? 0),
            'b2500'  => (int) ($row['b2500'] ?? 0),
            'b_inf'  => (int) ($row['b_inf'] ?? 0),
            'max_ms' => (int) ($row['max_ms'] ?? 0),
        ];
    }

    /** Clamp a caller-supplied limit to a sane, always-positive-int range. */
    private static function clampLimit(int $limit): int
    {
        return \max(1, \min(1000, $limit));
    }

    /** @return array<int,array<string,mixed>> */
    private function all(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return ?array<string,mixed> */
    private function one(string $sql, array $args = []): ?array
    {
        $rows = $this->all($sql, $args);

        return $rows[0] ?? null;
    }

    private function scalar(string $sql, array $args = [])
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);

        return $st->fetchColumn();
    }

    private static function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
