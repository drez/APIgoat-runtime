<?php

namespace ApiGoat\Ops;

/**
 * Pushes this app's ops problems + hourly request totals to the hub app
 * (Config::hubUrl(), authenticated with Config::hubKey() — the app's
 * ana_site sk_ secret on the hub). Called every ~5 min by Tick.
 *
 * What goes out (only this app's own rows, site_id = 0):
 *   - ops_req_hour    the current + previous hour (plus any backlog since
 *                     the last success), ABSOLUTE values — the hub upserts,
 *                     so re-sending an hour that is still filling is exact
 *   - ops_req_slow    every row (slow or 5xx: already problem-only)
 *   - ops_query_slow  every row
 *   - ops_sec_event   every row
 *   - ops_cron_run    failed runs only (ok = 0)
 *   - ops_server_snap alert snapshots only (disk or mem >= 90 % or a
 *                     service down)
 *
 * Append-only tables are read past a per-table id watermark and carry
 * their local id as src_id (the hub dedupes on site_id + src_id), so a
 * batch that was received but whose 2xx got lost is simply re-sent. The
 * watermarks (tmp/ops-forward.json) only move after a 2xx. Nothing here
 * throws: a failure is the one-line summary Tick logs.
 */
final class Forwarder
{
    /** Rows per table per run; a backlog drains over the next ticks. */
    public const BATCH = 2000;

    /** How far back the very first run sends hourly totals. */
    private const REQ_HOUR_BACKFILL = 7 * 86400;

    /** table => [columns sent, extra WHERE (problem filter) or ''] */
    private const APPEND_TABLES = [
        'ops_req_slow'    => [['route', 'method', 'path', 'status', 'ms', 'queries', 'ip', 'created_at'], ''],
        'ops_query_slow'  => [['route', 'ms', 'sql_hash', 'sql_text', 'created_at'], ''],
        'ops_sec_event'   => [['type', 'ip', 'detail', 'created_at'], ''],
        'ops_cron_run'    => [['job', 'started_at', 'ms', 'ok', 'summary', 'created_at'], 'AND ok = 0'],
        'ops_server_snap' => [['created_at', 'load1', 'mem_pct', 'disk_pct', 'services', 'f2b_banned', 'f2b', 'auth'],
            "AND (disk_pct >= 90 OR mem_pct >= 90 OR services LIKE '%:false%')"],
    ];

    private const REQ_HOUR_COLUMNS = ['hour', 'route', 'method', 'n', 'sum_ms', 'max_ms', 'n_4xx', 'n_5xx',
        'b100', 'b250', 'b500', 'b1000', 'b2500', 'b_inf', 'created_at'];

    /**
     * @param ?callable(string $url, string $key, string $gzBody): int $send test seam — returns the HTTP status
     */
    public static function run(\PDO $pdo, ?int $now = null, ?callable $send = null, ?string $stateFile = null): string
    {
        $url = Config::hubUrl();
        $key = Config::hubKey();
        if ($url === null || $key === null || Config::isHub()) {
            return 'forward: off';
        }
        if (!Config::isProduction()) {
            return 'forward: off (not production)';
        }
        $now ??= \time();
        $stateFile ??= self::defaultStateFile();

        try {
            $state = self::loadState($stateFile);
            [$payload, $next] = self::collect($pdo, $state, $now);
            $count = \array_sum(\array_map('count', $payload));
            if ($count === 0) {
                self::saveState($stateFile, $next);

                return 'forward: nothing new';
            }
            $body = \gzencode((string) \json_encode(['v' => 1, 'sent_at' => $now, 'rows' => $payload], \JSON_INVALID_UTF8_SUBSTITUTE));
            $status = ($send ?? [self::class, 'post'])($url, $key, $body);
            if ($status < 200 || $status >= 300) {
                return "forward: hub answered {$status} ({$count} rows kept for retry)";
            }
            self::saveState($stateFile, $next);

            return "forward: {$count} rows";
        } catch (\Throwable $e) {
            \error_log('[ops] forward failed: ' . $e->getMessage());

            return 'forward: failed (' . $e->getMessage() . ')';
        }
    }

    /**
     * The payload + the watermarks to save once the hub accepted it. Pure
     * over the DB (no I/O besides the SELECTs) so tests can assert exactly
     * what would be sent.
     *
     * @return array{0: array<string, list<array<string,mixed>>>, 1: array<string,int>}
     */
    public static function collect(\PDO $pdo, array $state, int $now): array
    {
        $payload = [];
        $next = $state;

        foreach (self::APPEND_TABLES as $table => [$cols, $filter]) {
            $pk = 'id_' . $table;
            $last = (int) ($state[$table] ?? 0);
            // Upper bound first: a filtered table (failed cron runs, alert
            // snapshots) must move its watermark past the rows it skipped.
            $hi = (int) $pdo->query("SELECT COALESCE(MAX({$pk}), 0) FROM {$table}")->fetchColumn();
            if ($hi <= $last) {
                continue;
            }
            $st = $pdo->prepare(
                "SELECT {$pk} AS src_id, " . \implode(', ', $cols) . " FROM {$table}
                 WHERE {$pk} > ? AND {$pk} <= ? AND site_id = 0 {$filter}
                 ORDER BY {$pk} LIMIT " . self::BATCH
            );
            $st->execute([$last, $hi]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            if (\count($rows) === self::BATCH) {
                $hi = (int) $rows[self::BATCH - 1]['src_id'];
            }
            if ($rows !== []) {
                $payload[$table] = $rows;
            }
            $next[$table] = $hi;
        }

        // Hourly totals: re-send from the previous hour (it may still have
        // been filling at the last send) — or the backfill window on the
        // first run. A truncated batch resumes from its last hour.
        $currentHour = \intdiv($now, 3600) * 3600;
        $from = isset($state['ops_req_hour']) ? (int) $state['ops_req_hour'] : $now - self::REQ_HOUR_BACKFILL;
        $st = $pdo->prepare(
            'SELECT ' . \implode(', ', self::REQ_HOUR_COLUMNS) . ' FROM ops_req_hour
             WHERE hour >= ? AND site_id = 0 ORDER BY hour LIMIT ' . self::BATCH
        );
        $st->execute([$from]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows !== []) {
            $payload['ops_req_hour'] = $rows;
        }
        $next['ops_req_hour'] = \count($rows) === self::BATCH
            ? (int) $rows[self::BATCH - 1]['hour']
            : $currentHour - 3600;

        return [$payload, $next];
    }

    private static function post(string $url, string $key, string $gzBody): int
    {
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => $gzBody,
            \CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'Content-Encoding: gzip',
            ],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 3,
            \CURLOPT_TIMEOUT        => 5,
            \CURLOPT_PROTOCOLS      => \CURLPROTO_HTTPS,
        ]);
        \curl_exec($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        return $status;
    }

    private static function defaultStateFile(): string
    {
        return (\defined('_BASE_DIR') ? _BASE_DIR : \sys_get_temp_dir() . '/') . 'tmp/ops-forward.json';
    }

    /** @return array<string,int> */
    private static function loadState(string $file): array
    {
        $raw = \is_file($file) ? @\file_get_contents($file) : false;
        $d = $raw !== false ? \json_decode($raw, true) : null;

        return \is_array($d) ? \array_map('intval', $d) : [];
    }

    private static function saveState(string $file, array $state): void
    {
        $tmp = $file . '.' . \getmypid();
        if (@\file_put_contents($tmp, (string) \json_encode($state)) !== false) {
            @\rename($tmp, $file);
        }
    }
}
