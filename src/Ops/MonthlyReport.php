<?php

namespace ApiGoat\Ops;

use ApiGoat\Auth\EmailPlaceholder;
use ApiGoat\Notify\Mailer;

/**
 * Monthly security + performance report (Task 8, Security & Performance
 * dashboards): a single HTML digest over ApiGoat\Ops\Stats (Task 7) — logins,
 * new Deny routes, new OAuth clients, token reuse/revoke, sec_event counts,
 * top php-error.log signatures, slowest routes, 5xx count, server health —
 * plus a fixed set of anomaly rules, stored in ops_report (period UNIQUE,
 * emitted by with_ops_monitor) and mailed to a group of active authy users.
 *
 * build() is pure given a Stats instance (and an optional error-log path):
 * no PDO write, no mail. run() is the orchestrator a cron job or the
 * `.admin/scripts/ops-report.php` CLI calls — it builds, upserts the
 * ops_report row (period is UNIQUE, so a re-run replaces it), resolves
 * recipients and mails them. The mailer is an injectable seam ($send)
 * defaulting to a thin wrapper over Notify\Mailer::send() so tests never
 * send real mail (R: no real email in Task 8's own test suite).
 *
 * Anomaly rules are pure (anomalies()) and take only scalars/arrays — no
 * Stats, no PDO — so they are unit-testable without a database
 * (RT/tests/Ops/MonthlyReportTest.php). The DB-backed half (run()'s upsert,
 * recipient resolution, the mailer seam) is tested against real MySQL in
 * P/.admin/tests/Custom/OpsMonthlyReportTest.php (R1 — no pdo_sqlite here).
 */
final class MonthlyReport
{
    /** "Top N" / "slowest N" / error-signature default limits used throughout the report. */
    private const TOP_LIMIT = 10;

    /** Stats::clampLimit()'s own ceiling — the widest "give me everything" query this class issues. */
    private const ALL_LIMIT = 1000;

    /** Disk-usage anomaly threshold (percent). */
    private const DISK_ANOMALY_PCT = 85.0;

    /** Failed-logins anomaly multiplier vs the previous month. */
    private const LOGIN_ANOMALY_MULTIPLIER = 3;

    /** p95-regression anomaly multiplier vs the previous month. */
    private const P95_ANOMALY_MULTIPLIER = 2;

    /**
     * Build the report for $period ('YYYY-MM'). Pure: no PDO write, no mail.
     * $logPath is the php-error.log to mine for top error signatures — pass
     * '' (the default) to skip that section entirely, which is also what a
     * missing/unreadable file falls back to.
     *
     * @return array{summary: array<string, mixed>, html: string}
     */
    public static function build(Stats $s, string $period, string $logPath = ''): array
    {
        self::assertPeriod($period);

        [$from, $to] = self::periodRange($period);
        $prevPeriod = self::previousPeriod($period);
        [$prevFrom, $prevTo] = self::periodRange($prevPeriod);

        $overview = $s->securityOverview($from, $to);
        $prevOverview = $s->securityOverview($prevFrom, $prevTo);
        $topFailing = $s->topFailing($from, $to, self::TOP_LIMIT);

        $newDeny = self::filterByTimestampField($s->denyRoutes(self::ALL_LIMIT), 'date_modification', $from, $to);
        $newOauth = self::filterByTimestampField($s->oauthClients(), 'created_at', $from, $to);

        $secEventCounts = $s->secEventCounts($from, $to);
        $tokenReuseCount = $secEventCounts['token_reuse'] ?? 0;
        $tokenRevokedCount = $secEventCounts['token_revoked'] ?? 0;

        $errorSigs = self::errorSignatures($logPath, $period, self::TOP_LIMIT);

        // One wide query for both "all routes this month" (anomaly matching
        // + 5xx total) and the top-10 display slice — Stats::slowestRoutes()
        // orders by total time spent (sum_ms desc), so the first 10 of the
        // wide result IS the "slowest 10 routes" the brief asks for.
        $currentRoutes = $s->slowestRoutes($from, $to, self::ALL_LIMIT);
        $prevRoutes = $s->slowestRoutes($prevFrom, $prevTo, self::ALL_LIMIT);
        $slowestTop = \array_slice($currentRoutes, 0, self::TOP_LIMIT);
        $total5xx = (int) \array_sum(\array_column($currentRoutes, 'n_5xx'));

        $prevP95ByKey = [];
        foreach ($prevRoutes as $r) {
            $prevP95ByKey[$r['route'] . '|' . $r['method']] = $r['p95_ms'];
        }

        $serverTrend = $s->serverTrend($from, $to);
        $maxLoad = self::maxOf($serverTrend, 'load1');
        $maxDisk = self::maxOf($serverTrend, 'disk_pct');
        $maxBans = self::maxOf($serverTrend, 'f2b_banned');

        $anomalies = self::anomalies(
            $overview['failed_logins'],
            $prevOverview['failed_logins'],
            (int) $tokenReuseCount,
            $maxDisk,
            $newDeny,
            $currentRoutes,
            $prevP95ByKey
        );

        $summary = [
            'period'            => $period,
            'previous_period'   => $prevPeriod,
            'failed_logins'     => $overview['failed_logins'],
            'ok_logins'         => $overview['ok_logins'],
            'rbac_denies'       => $overview['rbac_denies'],
            'sec_events'        => $overview['sec_events'],
            'active_tokens'     => $overview['active_tokens'],
            'new_deny_routes'   => \count($newDeny),
            'new_oauth_clients' => \count($newOauth),
            'token_reuse'       => $tokenReuseCount,
            'token_revoked'     => $tokenRevokedCount,
            'sec_event_counts'  => $secEventCounts,
            'top_error_signatures' => $errorSigs,
            'total_5xx'         => $total5xx,
            'max_load'          => $maxLoad,
            'max_disk_pct'      => $maxDisk,
            'max_f2b_banned'    => $maxBans,
            'anomalies'         => $anomalies,
        ];

        $html = self::renderHtml(
            $period,
            $prevPeriod,
            $overview,
            $topFailing,
            $newDeny,
            $newOauth,
            $secEventCounts,
            $errorSigs,
            $slowestTop,
            $total5xx,
            $maxLoad,
            $maxDisk,
            $maxBans,
            $anomalies
        );

        return ['summary' => $summary, 'html' => $html];
    }

    /**
     * Build the report, upsert it into ops_report (period is UNIQUE — a
     * re-run replaces html/summary/created_at, and emailed reflects THIS
     * run's outcome) and mail it to every active authy in $group.
     *
     * $group defaults to Config::get('report_to'). $send is an injectable
     * mailer seam — (list<string> $to, string $subject, string $html): bool
     * — defaulting to a thin wrapper over Notify\Mailer::send(); tests pass
     * a recorder so no real mail is ever sent from this codebase's own
     * suite. No recipients resolve => the report is still stored
     * (emailed=0), $send is never called.
     *
     * Returns a one-line summary (anomaly count + recipient outcome).
     */
    public static function run(\PDO $pdo, string $period, ?string $group = null, ?callable $send = null): string
    {
        self::assertPeriod($period);

        $group ??= (string) Config::get('report_to');
        $send ??= static function (array $to, string $subject, string $html): bool {
            return Mailer::send($to, $subject, $html);
        };

        $logPath = \defined('_BASE_DIR') ? _BASE_DIR . 'tmp/logs/php-error.log' : '';
        $built = self::build(new Stats($pdo), $period, $logPath);

        $recipients = self::recipients($pdo, $group);
        $emailed = 0;
        if ($recipients !== []) {
            $emailed = $send($recipients, "Security report {$period}", $built['html']) ? 1 : 0;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ops_report (period, html, summary, created_at, emailed)
             VALUES (:period, :html, :summary, :created_at, :emailed)
             ON DUPLICATE KEY UPDATE
                html = VALUES(html),
                summary = VALUES(summary),
                created_at = VALUES(created_at),
                emailed = VALUES(emailed)'
        );
        $stmt->execute([
            ':period'     => $period,
            ':html'       => $built['html'],
            ':summary'    => \json_encode($built['summary']),
            ':created_at' => \time(),
            ':emailed'    => $emailed,
        ]);

        $anomalyCount = \count($built['summary']['anomalies']);
        $recipientNote = $recipients === []
            ? 'no recipients'
            : \sprintf('%d recipient(s)%s', \count($recipients), $emailed ? '' : ' (send failed)');

        return \sprintf(
            'Security report %s: %d anomal%s, %s',
            $period,
            $anomalyCount,
            $anomalyCount === 1 ? 'y' : 'ies',
            $recipientNote
        );
    }

    /**
     * Pure file parsing of a PHP error_log (settings.defaults.php's format:
     * `[DD-Mon-YYYY HH:MM:SS TZ] message`). Reads $logPath and, if present,
     * $logPath . '.1' (the one rotated generation settings.defaults.php
     * keeps), counts only lines dated inside $period ('YYYY-MM'), and
     * returns the top $limit distinct signatures by count desc.
     *
     * A signature is the message with every quoted substring (single or
     * double) and every run of digits replaced by a placeholder, so e.g.
     * `Undefined array key "foo"` and `Undefined array key "bar"` — or the
     * same warning for row id 12 vs row id 45 — collapse into one line.
     *
     * Missing/unreadable file(s) => []. A line that doesn't match the
     * bracketed-date format (a stack-trace continuation, blank line, …) is
     * silently skipped, not counted as its own signature.
     *
     * @return list<array{sig: string, n: int}>
     */
    public static function errorSignatures(string $logPath, string $period, int $limit): array
    {
        $limit = \max(1, $limit);
        $counts = [];

        foreach ([$logPath, $logPath === '' ? '' : $logPath . '.1'] as $path) {
            if ($path === '' || !\is_file($path) || !\is_readable($path)) {
                continue;
            }
            $content = @\file_get_contents($path);
            if ($content === false || $content === '') {
                continue;
            }
            foreach (\explode("\n", $content) as $line) {
                $line = \rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                $parsed = self::parseErrorLogLine($line);
                if ($parsed === null || $parsed['period'] !== $period) {
                    continue;
                }
                $sig = self::signature($parsed['message']);
                $counts[$sig] = ($counts[$sig] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return [];
        }

        \arsort($counts);

        $out = [];
        foreach ($counts as $sig => $n) {
            $out[] = ['sig' => $sig, 'n' => $n];
            if (\count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The fixed anomaly rules, pure — no Stats, no PDO, so this is directly
     * unit-testable (RT/tests/Ops/MonthlyReportTest.php):
     *   - failed logins > 3x the previous month;
     *   - any token_reuse;
     *   - disk > 85%;
     *   - a new Deny route (one message per route in $newDenyRoutes, which
     *     the caller has already filtered to "modified within the period");
     *   - p95 of any route > 2x the previous month (matched by
     *     route + '|' + method against $prevP95ByKey).
     *
     * @param list<array{model: string, action: ?string, method: string}> $newDenyRoutes
     * @param list<array{route: string, method: string, p95_ms: int}> $currentRoutes
     * @param array<string, int> $prevP95ByKey "route|method" => previous month's p95_ms
     * @return list<string>
     */
    public static function anomalies(
        int $failedLogins,
        int $prevFailedLogins,
        int $tokenReuseCount,
        ?float $maxDiskPct,
        array $newDenyRoutes,
        array $currentRoutes,
        array $prevP95ByKey
    ): array {
        $out = [];

        if ($failedLogins > $prevFailedLogins * self::LOGIN_ANOMALY_MULTIPLIER) {
            $out[] = \sprintf(
                'Failed logins: %d this month vs %d last month (more than %dx)',
                $failedLogins,
                $prevFailedLogins,
                self::LOGIN_ANOMALY_MULTIPLIER
            );
        }

        if ($tokenReuseCount > 0) {
            $out[] = \sprintf('Token reuse detected: %d event(s)', $tokenReuseCount);
        }

        if ($maxDiskPct !== null && $maxDiskPct > self::DISK_ANOMALY_PCT) {
            $out[] = \sprintf(
                'Disk usage peaked at %.1f%% (over %d%%)',
                $maxDiskPct,
                (int) self::DISK_ANOMALY_PCT
            );
        }

        foreach ($newDenyRoutes as $r) {
            $out[] = \sprintf(
                'New Deny route: %s%s (%s)',
                $r['model'],
                $r['action'] !== null && $r['action'] !== '' ? '.' . $r['action'] : '',
                $r['method']
            );
        }

        foreach ($currentRoutes as $r) {
            $prevP95 = $prevP95ByKey[$r['route'] . '|' . $r['method']] ?? 0;
            if ($r['p95_ms'] > 0 && $prevP95 > 0 && $r['p95_ms'] > $prevP95 * self::P95_ANOMALY_MULTIPLIER) {
                $out[] = \sprintf(
                    'Route %s %s p95 %s vs %s last month (more than %dx)',
                    $r['method'],
                    $r['route'],
                    self::p95Label($r['p95_ms']),
                    self::p95Label($prevP95),
                    self::P95_ANOMALY_MULTIPLIER
                );
            }
        }

        return $out;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private static function assertPeriod(string $period): void
    {
        if (!\preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException("Invalid period '{$period}', expected 'YYYY-MM'");
        }
    }

    /** @return array{0: int, 1: int} [fromTs, toTs] — the whole month, inclusive. */
    private static function periodRange(string $period): array
    {
        $from = (int) \strtotime($period . '-01 00:00:00');
        $to = (int) \strtotime($period . '-01 +1 month') - 1;

        return [$from, $to];
    }

    private static function previousPeriod(string $period): string
    {
        return (string) \date('Y-m', (int) \strtotime($period . '-01 -1 month'));
    }

    /**
     * Keep only the rows of $rows whose $field (a DATETIME string, nullable)
     * falls inside [$from, $to] — used for "new Deny routes" / "new OAuth
     * clients this month" over Stats' all-time lists.
     */
    private static function filterByTimestampField(array $rows, string $field, int $from, int $to): array
    {
        $out = [];
        foreach ($rows as $r) {
            $raw = $r[$field] ?? null;
            if ($raw === null) {
                continue;
            }
            $ts = \strtotime((string) $raw);
            if ($ts !== false && $ts >= $from && $ts <= $to) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** Max of a nullable numeric column across $rows, or null when every value is null/absent. */
    private static function maxOf(array $rows, string $field): ?float
    {
        $values = [];
        foreach ($rows as $r) {
            if (isset($r[$field]) && $r[$field] !== null) {
                $values[] = (float) $r[$field];
            }
        }

        return $values === [] ? null : \max($values);
    }

    /**
     * p95FromBuckets()'s b_inf estimate is never exact past 2500ms — render
     * it as a bound, not a false-precision number. Plain text (callers that
     * embed it in HTML — anomalies() text, the slowest-routes table — are
     * responsible for htmlspecialchars()-ing it like any other dynamic
     * value, so this never double-escapes).
     */
    private static function p95Label(int $ms): string
    {
        return $ms > 2500 ? '>2500 ms' : $ms . ' ms';
    }

    /** @return ?array{period: string, message: string} */
    private static function parseErrorLogLine(string $line): ?array
    {
        if (!\preg_match('/^\[(\d{2})-([A-Za-z]{3})-(\d{4}) \d{2}:\d{2}:\d{2}(?: [^\]]*)?\]\s?(.*)$/', $line, $m)) {
            return null;
        }
        $month = self::monthNumber($m[2]);
        if ($month === null) {
            return null;
        }

        return [
            'period'  => \sprintf('%04d-%02d', (int) $m[3], $month),
            'message' => $m[4],
        ];
    }

    private static function monthNumber(string $abbr): ?int
    {
        static $map = [
            'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
        ];

        return $map[$abbr] ?? null;
    }

    /** Strip quoted substrings, then digit runs, so varying ids/values collapse into one signature. */
    private static function signature(string $message): string
    {
        $message = (string) \preg_replace('/\'[^\']*\'|"[^"]*"/', '?', $message);
        $message = (string) \preg_replace('/\d+/', '#', $message);

        return \trim($message);
    }

    /**
     * Active authy users in $group with a real (non-placeholder) email.
     * deactivate is a GoatCheese ENUM ("Yes"/"No") stored as its ordinal —
     * 1 is 'No' (not deactivated / active), per AuthyTableMap's fixed
     * valueSet, the same pattern Stats uses for api_rbac.rule/method.
     *
     * @return list<string>
     */
    private static function recipients(\PDO $pdo, string $group): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.email
             FROM authy a
             JOIN authy_group g ON g.id_authy_group = a.id_authy_group
             WHERE g.name = ? AND a.deactivate = 1'
        );
        $stmt->execute([$group]);
        $emails = \array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        return EmailPlaceholder::realRecipients($emails, 'MonthlyReport');
    }

    /**
     * @param array{failed_logins:int,ok_logins:int,rbac_denies:int,sec_events:int,active_tokens:int} $overview
     * @param list<array{ip:string,login:string,failures:int}> $topFailing
     * @param list<array{model:string,action:?string,method:string,count:int,date_modification:?string}> $newDeny
     * @param list<array{client_id:string,created_at:?string,active_tokens:int}> $newOauth
     * @param array<string,int> $secEventCounts
     * @param list<array{sig:string,n:int}> $errorSigs
     * @param list<array{route:string,method:string,n:int,avg_ms:float,p95_ms:int,n_5xx:int}> $slowestTop
     * @param list<string> $anomalies
     */
    private static function renderHtml(
        string $period,
        string $prevPeriod,
        array $overview,
        array $topFailing,
        array $newDeny,
        array $newOauth,
        array $secEventCounts,
        array $errorSigs,
        array $slowestTop,
        int $total5xx,
        ?float $maxLoad,
        ?float $maxDisk,
        ?int $maxBans,
        array $anomalies
    ): string {
        $h = static fn ($v): string => \htmlspecialchars((string) $v, \ENT_QUOTES, 'UTF-8');
        $th = 'style="padding:4px 8px;border:1px solid #ddd;text-align:left;background:#f0f0f0;"';
        $td = 'style="padding:4px 8px;border:1px solid #ddd;text-align:left;"';
        $tableOpen = '<table style="border-collapse:collapse;margin:0 0 16px;width:100%;max-width:640px;">';
        $h3 = 'style="margin:20px 0 6px;font-size:15px;"';

        $section = static function (string $title, array $headers, array $rows) use ($h, $th, $td, $tableOpen, $h3): string {
            $out = "<h3 {$h3}>" . $h($title) . '</h3>';
            $out .= $tableOpen . '<tr>';
            foreach ($headers as $head) {
                $out .= "<th {$th}>" . $h($head) . '</th>';
            }
            $out .= '</tr>';
            if ($rows === []) {
                $out .= '<tr><td ' . $td . ' colspan="' . \count($headers) . '">None</td></tr>';
            } else {
                foreach ($rows as $row) {
                    $out .= '<tr>';
                    foreach ($row as $cell) {
                        $out .= "<td {$td}>" . $h($cell) . '</td>';
                    }
                    $out .= '</tr>';
                }
            }

            return $out . '</table>';
        };

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.4;">';
        $html .= '<h2 style="margin:0 0 4px;font-size:18px;">Security &amp; Performance Report &mdash; ' . $h($period) . '</h2>';
        $html .= '<p style="margin:0 0 16px;color:#666;">Compared against ' . $h($prevPeriod) . '.</p>';

        $html .= "<h3 {$h3}>Anomalies</h3>";
        if ($anomalies === []) {
            $html .= '<p style="margin:0 0 16px;">Nothing notable this month.</p>';
        } else {
            $html .= '<ul style="margin:0 0 16px;padding-left:20px;">';
            foreach ($anomalies as $a) {
                $html .= '<li style="color:#b00020;">' . $h($a) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= $section('Logins', ['Failed', 'OK', 'RBAC denies', 'Sec events', 'Active tokens (now)'], [[
            $overview['failed_logins'], $overview['ok_logins'], $overview['rbac_denies'],
            $overview['sec_events'], $overview['active_tokens'],
        ]]);

        $html .= $section(
            'Top failing logins',
            ['IP', 'Login', 'Failures'],
            \array_map(static fn ($r) => [$r['ip'], $r['login'], $r['failures']], $topFailing)
        );

        $html .= $section(
            'New Deny routes this month',
            ['Model', 'Action', 'Method', 'Count (all-time)'],
            \array_map(static fn ($r) => [$r['model'], $r['action'] ?? '', $r['method'], $r['count']], $newDeny)
        );

        $html .= $section(
            'New OAuth clients this month',
            ['Client', 'Created', 'Active tokens (now)'],
            \array_map(static fn ($r) => [$r['client_id'], $r['created_at'] ?? '', $r['active_tokens']], $newOauth)
        );

        \arsort($secEventCounts);
        $html .= $section(
            'Security events by type',
            ['Type', 'Count'],
            \array_map(static fn ($type, $n) => [$type, $n], \array_keys($secEventCounts), \array_values($secEventCounts))
        );

        $html .= $section(
            'Top error log signatures',
            ['Signature', 'Count'],
            \array_map(static fn ($r) => [$r['sig'], $r['n']], $errorSigs)
        );

        $html .= '<h3 ' . $h3 . '>Slowest routes</h3>';
        $html .= $tableOpen . '<tr><th ' . $th . '>Route</th><th ' . $th . '>Method</th><th ' . $th
            . '>Requests</th><th ' . $th . '>p95</th><th ' . $th . '>5xx</th></tr>';
        if ($slowestTop === []) {
            $html .= '<tr><td ' . $td . ' colspan="5">None</td></tr>';
        } else {
            foreach ($slowestTop as $r) {
                $html .= '<tr><td ' . $td . '>' . $h($r['route']) . '</td><td ' . $td . '>' . $h($r['method'])
                    . '</td><td ' . $td . '>' . $h($r['n']) . '</td><td ' . $td . '>' . $h(self::p95Label($r['p95_ms']))
                    . '</td><td ' . $td . '>' . $h($r['n_5xx']) . '</td></tr>';
            }
        }
        $html .= '</table>';

        $html .= '<p style="margin:0 0 16px;"><strong>5xx responses this month:</strong> ' . $h($total5xx) . '</p>';

        $html .= "<h3 {$h3}>Server</h3>";
        $html .= '<p style="margin:0 0 16px;">'
            . '<strong>Max load:</strong> ' . ($maxLoad !== null ? $h(\number_format($maxLoad, 2)) : 'n/a')
            . ' &middot; <strong>Max disk:</strong> ' . ($maxDisk !== null ? $h(\number_format($maxDisk, 1)) . '%' : 'n/a')
            . ' &middot; <strong>Max fail2ban bans:</strong> ' . ($maxBans !== null ? $h($maxBans) : 'n/a')
            . '</p>';

        $html .= '</div>';

        return $html;
    }
}
