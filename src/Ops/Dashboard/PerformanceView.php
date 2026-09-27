<?php

namespace ApiGoat\Ops\Dashboard;

use ApiGoat\Ops\Stats;

/**
 * Performance dashboard (route Performance/dashboard, emitted by
 * with_ops_monitor for every app). Admin only. Server-renders every table
 * (works without JS); Scripts only draws the latency-trend and
 * server-mini-trend charts. Reads ApiGoat\Ops\Stats scoped by Scope: on
 * the hub a site selector picks one forwarding app (hourly totals, slow
 * requests/queries, failed cron runs and server alerts are forwarded; its
 * DB table sizes stay in its own dashboard). Extra tabs come from the
 * project seam (Extras::performanceTabs — apigoatacc adds Web vitals).
 *
 * Every dynamic value is htmlspecialchars-escaped — routes, paths, hosts,
 * job names/summaries and normalized SQL text are either attacker-influenced
 * (a crafted path/host/query shows up verbatim in these tables) or come from
 * the untrusted server snapshot file (service names), same threat model as
 * Security/View.php's f2b jail names.
 *
 * GET params (from/to/site) are read defensively: Slim happily turns
 * `from[]=x` into an array, and passing that straight to a string-typed
 * helper (self::date()) is a TypeError, not a graceful fallback — every
 * param is is_string()-checked before use (Security/View.php does the same).
 */
final class PerformanceView
{
    public function __construct(private ?bool $admin = null) {}

    public function render(array $q): string
    {
        if (!$this->isAdmin()) {
            return '<div class="ops-dash"><div class="ops-card">' . htmlspecialchars(_('Forbidden')) . '</div></div>';
        }
        $pdo = \Propel::getConnection(_DATA_SRC);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);

        $toRaw = $q['to'] ?? '';
        $fromRaw = $q['from'] ?? '';
        $to = self::date(\is_string($toRaw) ? $toRaw : '') ?? date('Y-m-d');
        $from = self::date(\is_string($fromRaw) ? $fromRaw : '') ?? date('Y-m-d', strtotime('-29 days'));
        [$f, $t] = [strtotime("$from 00:00:00"), strtotime("$to 23:59:59")];

        $scope = new Scope($q);
        $remote = $scope->remote() !== null;
        $siteCol = $scope->showsSiteColumn();
        $siteTd = fn (array $r) => $siteCol ? '<td>' . $e($scope->label((int) $r['site_id'])) . '</td>' : '';
        $siteTh = $siteCol ? '<th>' . htmlspecialchars(_('Site'), ENT_QUOTES) . '</th>' : '';
        $st = new Stats($pdo, $scope->statsSites());

        $o = $st->perfOverview($f, $t);
        $trend = $st->latencyTrend($f, $t);
        $slowestRoutes = $st->slowestRoutes($f, $t, 20);
        $slowRequests = $st->slowRequestGroups($f, $t, 50);
        $slowQueries = $st->slowQueryGroups($f, $t, 50);
        $tableSizes = $st->tableSizes(20);
        $cronRuns = $st->cronRuns(20);
        $serverSt = new Stats($pdo, $scope->serverSites());
        $server = $serverSt->serverLatest();
        $serverTrend = $serverSt->serverTrend(time() - 86400, time());

        // A KPI tile; $spark = [timestamps, values, unit] adds a sparkline of
        // the tile's history over the selected range (drawn by Scripts).
        $kpi = fn ($label, $val, ?array $spark = null) => '<div class="ops-kpi"><div class="ops-kpi-l">' . $e($label) . '</div><div class="ops-kpi-v">' . $e($val) . '</div>'
            . ($spark !== null && \count($spark[1]) > 1
                ? '<canvas class="ops-spark" height="36" aria-label="' . $e(sprintf(_('%s trend'), $label)) . '" role="img" data-ts="' . $e(json_encode($spark[0])) . '" data-v="' . $e(json_encode($spark[1])) . '" data-unit="' . $e($spark[2]) . '"></canvas>'
                : '')
            . '</div>';
        $ts = array_column($trend, 'hour');
        $slowByBucket = array_column($st->slowQueryTrend($f, $t), 'n', 'hour');
        $serverRange = $serverSt->serverTrend($f, $t);
        $serverTs = array_column($serverRange, 'created_at');
        $sparkServer = fn (string $col, string $unit) => [$serverTs, array_column($serverRange, $col), $unit];
        $unavailable = _('unavailable');
        // Header with a hover description (native title tooltip).
        $th = fn ($label, $tip) => '<th class="ops-tip" title="' . $e($tip) . '">' . $e($label) . '</th>';
        // '(unmatched)' = no route was resolved; explain it on hover.
        $routeCell = fn ($route) => $route === '(unmatched)'
            ? '<td><span class="ops-tip" title="' . $e(_('No application route handled these requests: they were stopped before routing (not logged in / RBAC or token refusal, e.g. a redirect to login or a 401/403) or matched no route at all (404/405: bots, scanners, typos, removed URLs). Their time is mostly the security checks, not page code.')) . '">' . $e($route) . '</span></td>'
            : '<td>' . $e($route) . '</td>';


        $slowestRoutesRows = '';
        foreach ($slowestRoutes as $r) {
            $slowestRoutesRows .= '<tr>' . $siteTd($r) . $routeCell($r['route']) . '<td>' . $e($r['method']) . '</td><td>' . (int) $r['n'] . '</td>'
                . '<td>' . $e($r['avg_ms']) . '</td><td>' . $e(self::fmtP95($r['p95_ms'])) . '</td><td>' . (int) $r['n_5xx'] . '</td></tr>';
        }
        $slowestRoutesTable = '<div class="ops-card"><h3>' . $e(_('Slowest routes')) . '</h3>'
            . '<table class="ops-t"><tr>' . $siteTh . $th(_('Route'), _('Matched route pattern (e.g. /Client/edit/{id}); all URLs of one pattern are summed. (unmatched) = requests no route handled.')) . '<th>' . $e(_('Method')) . '</th><th>' . $e(_('Requests')) . '</th><th>' . $e(_('Avg ms')) . '</th><th>' . $e(_('p95')) . '</th><th>' . $e(_('5xx')) . '</th></tr>'
            . $slowestRoutesRows . ($slowestRoutes ? '' : '<tr><td colspan="' . ($siteCol ? 7 : 6) . '" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';

        $slowRequestsRows = '';
        foreach ($slowRequests as $r) {
            $slowRequestsRows .= '<tr>' . $siteTd($r) . $routeCell($r['route']) . '<td>' . $e($r['method']) . '</td><td>' . (int) $r['n'] . '</td>'
                . '<td>' . $e($r['avg_ms']) . '</td><td>' . (int) $r['max_ms'] . '</td>'
                . '<td>' . ($r['avg_queries'] !== null ? $e($r['avg_queries']) : '') . '</td><td>' . (int) $r['n_5xx'] . '</td>'
                . '<td>' . $e(date('Y-m-d H:i:s', $r['last_at'])) . '</td></tr>';
        }
        $slowRequestsTable = '<div class="ops-card"><h3>' . $e(_('Slow requests')) . '</h3>'
            . '<table class="ops-t"><tr>' . $siteTh . '<th>' . $e(_('Route')) . '</th><th>' . $e(_('Method')) . '</th><th>' . $e(_('Count')) . '</th><th>' . $e(_('Avg ms')) . '</th><th>' . $e(_('Max ms')) . '</th><th>' . $e(_('Avg queries')) . '</th><th>' . $e(_('5xx')) . '</th><th>' . $e(_('Last seen')) . '</th></tr>'
            . $slowRequestsRows . ($slowRequests ? '' : '<tr><td colspan="' . ($siteCol ? 9 : 8) . '" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';

        $slowQueriesRows = '';
        foreach ($slowQueries as $r) {
            $routes = $r['routes'];
            $routeCell = $e(implode(', ', \array_slice($routes, 0, 3))) . (\count($routes) > 3 ? ' ' . $e(sprintf(_('+%d more'), \count($routes) - 3)) : '');
            $slowQueriesRows .= '<tr>' . $siteTd($r) . '<td><code>' . $e($r['sql_text'] ?? '') . '</code></td><td>' . (int) $r['n'] . '</td>'
                . '<td>' . $e($r['avg_ms']) . '</td><td>' . (int) $r['max_ms'] . '</td>'
                . '<td>' . $routeCell . '</td><td>' . $e(date('Y-m-d H:i:s', $r['last_at'])) . '</td></tr>';
        }
        $slowQueriesTable = '<div class="ops-card"><h3>' . $e(_('Slow queries')) . '</h3>'
            . '<table class="ops-t"><tr>' . $siteTh . '<th>' . $e(_('SQL')) . '</th><th>' . $e(_('Count')) . '</th><th>' . $e(_('Avg ms')) . '</th><th>' . $e(_('Max ms')) . '</th><th>' . $e(_('Routes')) . '</th><th>' . $e(_('Last seen')) . '</th></tr>'
            . $slowQueriesRows . ($slowQueries ? '' : '<tr><td colspan="' . ($siteCol ? 7 : 6) . '" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';

        $tableSizesRows = '';
        foreach ($tableSizes as $r) {
            $tableSizesRows .= '<tr><td>' . $e($r['table']) . '</td><td>' . (int) $r['rows'] . '</td>'
                . '<td>' . $e(self::fmtBytes($r['data_bytes'])) . '</td><td>' . $e(self::fmtBytes($r['index_bytes'])) . '</td>'
                . '<td>' . $e(self::fmtBytes($r['total_bytes'])) . '</td></tr>';
        }
        $tableSizesTable = '<div class="ops-card"><h3>' . $e(_('DB table sizes')) . '</h3>'
            . '<table class="ops-t"><tr><th>' . $e(_('Table')) . '</th><th>' . $e(_('Rows')) . '</th><th>' . $e(_('Data')) . '</th><th>' . $e(_('Index')) . '</th><th>' . $e(_('Total')) . '</th></tr>'
            . $tableSizesRows . ($tableSizes ? '' : '<tr><td colspan="5" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';
        if ($remote) {
            $tableSizesTable = $scope->remoteCard(_('DB table sizes'), 'Performance/dashboard');
        }

        $cronRunsRows = '';
        foreach ($cronRuns as $r) {
            $statusClass = $r['ok'] ? 'low' : 'high';
            $statusLabel = $r['ok'] ? _('OK') : _('Failed');
            $cronRunsRows .= '<tr>' . $siteTd($r) . '<td>' . $e($r['job']) . '</td>'
                . '<td>' . ($r['started_at'] !== null ? $e(date('Y-m-d H:i:s', $r['started_at'])) : '') . '</td>'
                . '<td>' . ($r['ms'] !== null ? (int) $r['ms'] : '') . '</td>'
                . '<td><span class="cl-status cl-status-' . $e($statusClass) . '">' . $e($statusLabel) . '</span></td>'
                . '<td>' . $e($r['summary'] ?? '') . '</td>'
                . '<td>' . $e(date('Y-m-d H:i:s', $r['created_at'])) . '</td></tr>';
        }
        $cronRunsTable = '<div class="ops-card"><h3>' . $e(_('Cron runs')) . '</h3>'
            . ($remote || $siteCol ? '<p class="ops-foot">' . $e(_('Other sites send failed runs only.')) . '</p>' : '')
            . '<table class="ops-t"><tr>' . $siteTh . '<th>' . $e(_('Job')) . '</th><th>' . $e(_('Started')) . '</th><th>' . $e(_('ms')) . '</th><th>' . $e(_('Status')) . '</th><th>' . $e(_('Summary')) . '</th><th>' . $e(_('When')) . '</th></tr>'
            . $cronRunsRows . ($cronRuns ? '' : '<tr><td colspan="' . ($siteCol ? 7 : 6) . '" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';

        if ($server === null) {
            $serverCard = '<div class="ops-card"><h3>' . $e(_('Server')) . '</h3><p class="ops-empty">' . $e($unavailable) . '</p></div>';
        } else {
            $svcBadges = '';
            foreach ($server['services'] as $svc => $up) {
                $svcBadges .= '<span class="cl-status cl-status-' . ($up ? 'low' : 'high') . '">' . $e($svc) . ': ' . $e($up ? _('up') : _('down')) . '</span>';
            }
            $ageMin = (int) round($server['age_s'] / 60);
            $serverCard = '<div class="ops-card"><h3>' . $e(_('Server')) . '</h3>'
                . '<p class="ops-foot">' . $e(sprintf(_('Snapshot age: %d min'), $ageMin))
                . ($remote ? ' · ' . $e(_('latest ALERT snapshot (only alerts are forwarded)')) : '') . '</p>'
                . '<div class="ops-svc">' . ($svcBadges ?: '<span class="ops-empty">' . $e(_('No services reported')) . '</span>') . '</div>'
                . '<canvas class="ops-mini" id="perf-server-trend" height="60" data-series="' . $e(json_encode($serverTrend)) . '"></canvas>'
                . '</div>';
        }

        // MCP usage (ops_mcp_hour): only on a with_mcp project — or the hub,
        // which holds every forwarding app's rows.
        $mcp = $st->mcpUsage($f, $t, 50);
        $mcpTable = null;
        if ($mcp !== null) {
            $mcpRows = '';
            foreach ($mcp as $r) {
                $errCls = $r['n_err'] > 0 ? 'high' : 'low';
                $mcpRows .= '<tr>' . $siteTd($r) . '<td>' . $e($r['tool']) . '</td><td>' . $e($r['client'] !== '' ? $r['client'] : '—') . '</td>'
                    . '<td>' . (int) $r['n'] . '</td>'
                    . '<td>' . ($r['n_err'] > 0 ? '<span class="cl-status cl-status-' . $errCls . '">' . (int) $r['n_err'] . '</span>' : '0') . '</td>'
                    . '<td>' . (int) $r['n_denied'] . '</td>'
                    . '<td>' . $e($r['avg_ms']) . '</td><td>' . (int) $r['max_ms'] . '</td></tr>';
            }
            $mcpTable = '<div class="ops-card"><h3>' . $e(_('MCP')) . '</h3>'
                . '<table class="ops-t"><tr>' . $siteTh
                . $th(_('Tool'), _('MCP tool called (tools/call), or the protocol method (initialize, tools/list).'))
                . $th(_('Client'), _('OAuth client the AI assistant connected with (the access token audience).'))
                . $th(_('Calls'), _('Calls in the selected range.'))
                . $th(_('Errors'), _('Calls that failed: tool error, unknown tool/method or an exception.'))
                . $th(_('Denied'), _('Calls refused because the user has no access to the tool.'))
                . $th(_('Avg ms'), _('Average server time per call.'))
                . $th(_('Max ms'), _('Slowest single call.'))
                . '</tr>'
                . $mcpRows . ($mcp ? '' : '<tr><td colspan="' . ($siteCol ? 8 : 7) . '" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
                . '</table></div>';
        }

        $tabs = [
            [_('Slowest routes'), $slowestRoutesTable],
            [_('Slow requests'), $slowRequestsTable],
            [_('Slow queries'), $slowQueriesTable],
            [_('DB table sizes'), $tableSizesTable],
            [_('Cron runs'), $cronRunsTable],
            [_('Server'), $serverCard],
        ];
        if ($mcpTable !== null) {
            $tabs[] = [_('MCP'), $mcpTable];
        }
        foreach (Extras::performanceTabs($scope->selected(), $f, $t) as $tab) {
            $tabs[] = $tab;
        }

        return Styles::css() . Controls::css()
            . '<div class="ops-dash">'
            . '<form class="ops-head" method="get"><h2>' . $e(_('Performance')) . '</h2>'
            . $scope->select()
            . '<input class="dash-input" type="date" name="from" value="' . $e($from) . '"> <input class="dash-input" type="date" name="to" value="' . $e($to) . '">'
            . '<button type="submit" class="dash-btn dash-btn--primary"><i class="ri-equalizer-line"></i><span>' . $e(_('Apply')) . '</span></button></form>'
            . '<div class="ops-kpis">'
            . $kpi(_('Requests'), $o['requests'], [$ts, array_column($trend, 'n'), ''])
            . $kpi(_('Avg latency'), $o['avg_ms'] . ' ms', [$ts, array_column($trend, 'avg_ms'), ' ms'])
            . $kpi(_('p95 latency'), self::fmtP95($o['p95_ms']), [$ts, array_column($trend, 'p95_ms'), ' ms'])
            . $kpi(_('5xx rate'), round($o['rate_5xx'] * 100, 2) . '%', [$ts, array_map(fn ($r) => round($r['rate_5xx'] * 100, 2), $trend), '%'])
            . $kpi(_('Slow queries'), $o['slow_queries'], [$ts, array_map(fn ($h) => $slowByBucket[$h] ?? 0, $ts), ''])
            . $kpi(_('Load (1m)'), $server !== null && $server['load1'] !== null ? $server['load1'] : $unavailable, $sparkServer('load1', ''))
            . $kpi(_('Memory'), $server !== null && $server['mem_pct'] !== null ? $server['mem_pct'] . '%' : $unavailable, $sparkServer('mem_pct', '%'))
            . $kpi(_('Disk'), $server !== null && $server['disk_pct'] !== null ? $server['disk_pct'] . '%' : $unavailable, $sparkServer('disk_pct', '%'))
            . '</div>'
            . '<div class="ops-card"><h3>' . $e(_('Latency trend')) . '</h3>'
            . '<canvas id="perf-latency-trend" height="90" data-series="' . $e(json_encode($trend)) . '"></canvas></div>'
            . Tabs::render('performance', $tabs)
            . '</div>'
            . Scripts::performance();
    }

    /**
     * ops_req_hour's b_inf bucket (and thus p95FromBuckets()) cannot resolve
     * an exact value past 2500ms — Stats returns either the real max_ms or
     * the 2501 sentinel. Rather than distinguish those two cases here (both
     * mean "the p95 is somewhere past the last known bound"), any p95 over
     * 2500 renders the same way.
     */
    private static function fmtP95(int $ms): string
    {
        return $ms > 2500 ? '>2500 ms' : $ms . ' ms';
    }

    private static function fmtBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    private static function date(string $s): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $s);

        return $d && $d->format('Y-m-d') === $s ? $s : null;
    }

    private function isAdmin(): bool
    {
        if ($this->admin !== null) {
            return $this->admin;
        }
        // Same authority as Security/View::isAdmin (AuthySession::isAdmin → group === 'Admin').
        return isset($_SESSION[_AUTH_VAR])
            && is_object($_SESSION[_AUTH_VAR])
            && $_SESSION[_AUTH_VAR]->isAdmin();
    }
}
