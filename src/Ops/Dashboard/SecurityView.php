<?php

namespace ApiGoat\Ops\Dashboard;

use ApiGoat\Ops\IpInfo;
use ApiGoat\Ops\SecEvent;
use ApiGoat\Ops\Stats;

/**
 * Security dashboard (route Security/dashboard, emitted by with_ops_monitor
 * for every app). Admin only. Server-renders every table so it works
 * without JS; Scripts only draws the login-trend chart. Read-only over
 * ApiGoat\Ops\Stats, scoped by Scope: on the hub a site selector picks one
 * forwarding app, whose logins / deny routes / OAuth clients / reports stay
 * in that app's own dashboard (only its security events are forwarded).
 *
 * Every dynamic value is htmlspecialchars-escaped — logins, IPs, routes,
 * sec-event detail/types, and the jail/service names decoded from
 * ops_server_snap.f2b are attacker-controlled or come from a
 * web-user-writable snapshot file, not just "generated" data. The stored
 * ops_report.html column is not embedded raw either: it renders inside a
 * sandboxed <iframe srcdoc> (escaped into the attribute), so the archived
 * report keeps its own formatting but can never run script or touch this
 * page even if a future generator bug let markup through.
 *
 * GET params (from/to/type) are is_string()-checked before use — Slim turns
 * `from[]=x` into an array, which would be a TypeError in self::date().
 */
final class SecurityView
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
        $typeRaw = $q['type'] ?? null;
        $to = self::date(\is_string($toRaw) ? $toRaw : '') ?? date('Y-m-d');
        $from = self::date(\is_string($fromRaw) ? $fromRaw : '') ?? date('Y-m-d', strtotime('-29 days'));
        [$f, $t] = [strtotime("$from 00:00:00"), strtotime("$to 23:59:59")];

        $type = \is_string($typeRaw) && \in_array($typeRaw, SecEvent::TYPES, true) ? $typeRaw : null;

        $scope = new Scope($q);
        $remote = $scope->remote() !== null;
        $siteCol = $scope->showsSiteColumn();
        $st = new Stats($pdo, $scope->statsSites());
        $o = $st->securityOverview($f, $t);
        $trend = $st->loginTrend($f, $t);
        $topFailing = $st->topFailing($f, $t, 20);
        $counts = $st->secEventCounts($f, $t);
        $events = $st->secEventGroups($f, $t, $type, 50);
        $denyRoutes = $st->denyRoutes(20);
        $oauthClients = $st->oauthClients();
        $server = (new Stats($pdo, $scope->serverSites()))->serverLatest();

        // IPs shown by name: reverse DNS (else the IP) + RDAP owner, from the
        // ops_ip_info cache the background tick fills (IpInfo).
        $allIps = array_column($topFailing, 'ip');
        foreach ($events as $ev) {
            $allIps = array_merge($allIps, $ev['ip_list'], [$ev['last_ip'] ?? '']);
        }
        $ipInfo = IpInfo::map($pdo, $allIps);
        $ipOrg = fn (string $ip) => implode(' · ', array_filter([$ipInfo[$ip]['org'] ?? null, $ipInfo[$ip]['country'] ?? null]));
        $ipLine = fn (string $ip) => implode(' — ', array_filter([$ip, $ipInfo[$ip]['host'] ?? null, $ipOrg($ip)]));
        $ipCell = function (?string $ip, array $all = [], int $total = 0) use ($e, $ipInfo, $ipOrg, $ipLine): string {
            if ($ip === null || $ip === '') {
                return '<td></td>';
            }
            $host = $ipInfo[$ip]['host'] ?? null;
            $org = $ipOrg($ip);
            $others = array_values(array_diff($all, [$ip]));
            return '<td class="ops-ip"><span' . ($host !== null ? ' class="ops-tip" title="' . $e($ip) . '"' : '') . '>' . $e($host ?? $ip) . '</span>'
                . ($others ? ' <span class="ops-foot ops-tip" title="' . $e(implode("\n", array_map($ipLine, $all))) . '">' . $e(sprintf(_('+%d more'), max(count($others), $total - 1))) . '</span>' : '')
                . ($org !== '' ? '<div class="ops-foot">' . $e($org) . '</div>' : '')
                . '</td>';
        };

        $kpi = fn ($label, $val) => '<div class="ops-kpi"><div class="ops-kpi-l">' . $e($label) . '</div><div class="ops-kpi-v">' . $e($val) . '</div></div>';

        $typeOptions = '<option value="">' . $e(_('All types')) . '</option>';
        foreach (SecEvent::TYPES as $tp) {
            $sel = $type === $tp ? ' selected' : '';
            $typeOptions .= '<option value="' . $e($tp) . "\"$sel>" . $e($tp) . '</option>';
        }

        $countBadges = '';
        foreach ($counts as $tp => $n) {
            $countBadges .= '<span class="cl-status cl-status-' . $e(self::severity($tp)) . '">' . $e($tp) . ': ' . (int) $n . '</span>';
        }

        $eventsRows = '';
        foreach ($events as $ev) {
            $when = date('Y-m-d H:i', $ev['last_at']);
            if ($ev['n'] > 1 && date('Y-m-d H:i', $ev['first_at']) !== $when) {
                $when = date('Y-m-d H:i', $ev['first_at']) . ' → ' . $when;
            }
            $eventsRows .= '<tr>' . ($siteCol ? '<td>' . $e($scope->label($ev['site_id'])) . '</td>' : '') . '<td><span class="cl-status cl-status-' . $e(self::severity($ev['type'])) . '">' . $e($ev['type']) . '</span></td>'
                . '<td>' . $e($ev['detail']) . '</td>'
                . '<td>' . (int) $ev['n'] . '</td>'
                . $ipCell($ev['last_ip'], $ev['ip_list'], $ev['ips'])
                . '<td>' . ($ev['users'] > 0 ? (int) $ev['users'] : '') . '</td>'
                . '<td>' . $e($when) . '</td></tr>';
        }
        $eventsTable = '<div class="ops-card"><h3>' . $e(_('Security events')) . '</h3>'
            . '<div class="ops-counts">' . ($countBadges ?: '<span class="ops-empty">' . $e(_('No events')) . '</span>') . '</div>'
            . '<table class="ops-t"><tr>' . ($siteCol ? '<th>' . $e(_('Site')) . '</th>' : '') . '<th>' . $e(_('Type')) . '</th><th>' . $e(_('Detail')) . '</th><th>' . $e(_('Count')) . '</th><th>' . $e(_('IP')) . '</th><th>' . $e(_('Users')) . '</th><th>' . $e(_('Seen')) . '</th></tr>'
            . $eventsRows . ($events ? '' : '<tr><td colspan="' . ($siteCol ? 7 : 6) . '" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';

        $topFailingRows = '';
        foreach ($topFailing as $r) {
            $topFailingRows .= '<tr>' . $ipCell($r['ip']) . '<td>' . $e($r['login']) . '</td><td>' . (int) $r['failures'] . '</td></tr>';
        }
        $topFailingTable = '<div class="ops-card"><h3>' . $e(_('Top failing logins')) . '</h3>'
            . '<table class="ops-t"><tr><th>' . $e(_('IP')) . '</th><th>' . $e(_('Login')) . '</th><th>' . $e(_('Failures')) . '</th></tr>'
            . $topFailingRows . ($topFailing ? '' : '<tr><td colspan="3" class="ops-empty">' . $e(_('No data')) . '</td></tr>')
            . '</table></div>';

        if ($remote) {
            $topFailingTable = $scope->remoteCard(_('Top failing logins'), 'Security/dashboard');
        }

        $denyRows = '';
        foreach ($denyRoutes as $r) {
            $denyRows .= '<tr><td>' . $e($r['model']) . '</td><td>' . $e($r['action'] ?? '') . '</td><td>' . $e($r['method']) . '</td><td>' . (int) $r['count'] . '</td><td>' . $e($r['date_modification'] ?? '') . '</td></tr>';
        }
        $denyTable = '<div class="ops-card"><h3>' . $e(_('Deny routes (all-time)')) . '</h3>'
            . '<table class="ops-t"><tr><th>' . $e(_('Model')) . '</th><th>' . $e(_('Action')) . '</th><th>' . $e(_('Method')) . '</th><th>' . $e(_('Count')) . '</th><th>' . $e(_('Modified')) . '</th></tr>'
            . $denyRows . ($denyRoutes ? '' : '<tr><td colspan="5" class="ops-empty">' . $e(_('No deny rules')) . '</td></tr>')
            . '</table></div>';

        if ($remote) {
            $denyTable = $scope->remoteCard(_('Deny routes'), 'Security/dashboard');
        }

        $oauthRows = '';
        foreach ($oauthClients as $c) {
            $oauthRows .= '<tr><td>' . $e($c['client_id']) . '</td><td>' . $e($c['created_at'] ?? '') . '</td><td>' . (int) $c['active_tokens'] . '</td></tr>';
        }
        $oauthTable = '<div class="ops-card"><h3>' . $e(_('OAuth clients')) . '</h3>'
            . '<table class="ops-t"><tr><th>' . $e(_('Client ID')) . '</th><th>' . $e(_('Created')) . '</th><th>' . $e(_('Active tokens (now)')) . '</th></tr>'
            . $oauthRows . ($oauthClients ? '' : '<tr><td colspan="3" class="ops-empty">' . $e(_('No OAuth clients')) . '</td></tr>')
            . '</table></div>';

        if ($remote) {
            $oauthTable = $scope->remoteCard(_('OAuth clients'), 'Security/dashboard');
        }

        if ($server === null) {
            $serverCard = '<div class="ops-card"><h3>' . $e(_('Fail2ban jails')) . '</h3><p class="ops-empty">' . $e(_('unavailable')) . '</p></div>';
        } else {
            $jailRows = '';
            foreach ($server['f2b'] as $jail => $count) {
                $jailRows .= '<tr><td>' . $e($jail) . '</td><td>' . (int) $count . '</td></tr>';
            }
            $ageMin = (int) round($server['age_s'] / 60);
            $serverCard = '<div class="ops-card"><h3>' . $e(_('Fail2ban jails')) . '</h3>'
                . '<p class="ops-foot">' . $e(sprintf(_('Snapshot age: %d min'), $ageMin))
                . ($remote ? ' · ' . $e(_('latest ALERT snapshot (only alerts are forwarded)')) : '') . '</p>'
                . '<table class="ops-t"><tr><th>' . $e(_('Jail')) . '</th><th>' . $e(_('Banned')) . '</th></tr>'
                . $jailRows . ($jailRows ? '' : '<tr><td colspan="2" class="ops-empty">' . $e(_('No active jails')) . '</td></tr>')
                . '</table></div>';
        }

        $report = $scope->statsSites() === null || \in_array(0, $scope->statsSites(), true) ? self::lastReport($pdo) : null;
        if ($remote) {
            $reportCard = $scope->remoteCard(_('Monthly report'), 'Security/dashboard');
        } elseif ($report === null) {
            $reportCard = '<div class="ops-card"><h3>' . $e(_('Monthly report')) . '</h3><p class="ops-empty">' . $e(_('No report yet')) . '</p></div>';
        } else {
            $reportCard = '<div class="ops-card"><h3>' . $e(_('Monthly report')) . '</h3>'
                . '<p>' . $e($report['period']) . ' &middot; ' . $e(date('Y-m-d', $report['created_at'])) . '</p>'
                . '<details><summary class="dash-btn">' . $e(_('View report')) . '</summary>'
                . '<iframe class="ops-report-frame" sandbox srcdoc="' . $e($report['html'] ?? '') . '" title="' . $e(_('Monthly report')) . '"></iframe>'
                . '</details>'
                . '</div>';
        }

        $na = $remote ? '—' : null;
        $trendCard = $remote
            ? $scope->remoteCard(_('Login trend'), 'Security/dashboard')
            : '<div class="ops-card"><h3>' . $e(_('Login trend')) . '</h3>'
                . '<canvas id="ops-trend" height="90" data-series="' . $e(json_encode($trend)) . '"></canvas></div>';

        return Styles::css() . Controls::css()
            . '<div class="ops-dash">'
            . '<form class="ops-head" method="get"><h2>' . $e(_('Security')) . '</h2>'
            . $scope->select()
            . '<input class="dash-input" type="date" name="from" value="' . $e($from) . '"> <input class="dash-input" type="date" name="to" value="' . $e($to) . '">'
            . '<select class="dash-input" name="type">' . $typeOptions . '</select>'
            . '<button type="submit" class="dash-btn dash-btn--primary"><i class="ri-equalizer-line"></i><span>' . $e(_('Apply')) . '</span></button></form>'
            . '<div class="ops-kpis">'
            . $kpi(_('Failed logins'), $na ?? $o['failed_logins'])
            . $kpi(_('OK logins'), $na ?? $o['ok_logins'])
            . $kpi(_('RBAC denies'), $o['rbac_denies'])
            . $kpi(_('Security events'), $o['sec_events'])
            . $kpi(_('Active tokens (now)'), $na ?? $o['active_tokens'])
            . '</div>'
            . $trendCard
            . Tabs::render('security', [
                [_('Top failing logins'), $topFailingTable],
                [_('Security events'), $eventsTable],
                [_('Deny routes'), $denyTable],
                [_('OAuth clients'), $oauthTable],
                [_('Fail2ban jails'), $serverCard],
                [_('Monthly report'), $reportCard],
            ])
            . '</div>'
            . Scripts::security();
    }

    /** @return ?array{period:string, html:?string, created_at:int} */
    private static function lastReport(\PDO $pdo): ?array
    {
        $stmt = $pdo->query('SELECT period, html, created_at FROM ops_report WHERE site_id = 0 ORDER BY created_at DESC LIMIT 1');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'period'     => (string) $row['period'],
            'html'       => $row['html'] !== null ? (string) $row['html'] : null,
            'created_at' => (int) $row['created_at'],
        ];
    }

    /**
     * Maps a SecEvent type to a `cl-status-{high,normal,low}` pill (colors
     * come from the existing $cl-status-colors map in _clientv2.scss — no
     * new CSS, no hardcoded colors here).
     */
    private static function severity(string $type): string
    {
        static $high = ['rbac_deny', 'csrf', 'token_reuse', 'token_revoked', 'jwt_refused', 'google_reject'];
        static $normal = ['access_denied', 'switch_rejected', 'reauth_throttled'];

        if (\in_array($type, $high, true)) {
            return 'high';
        }
        if (\in_array($type, $normal, true)) {
            return 'normal';
        }

        return 'low';
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
        // Same authority as Analytics/View::isAdmin (AuthySession::isAdmin → group === 'Admin').
        return isset($_SESSION[_AUTH_VAR])
            && is_object($_SESSION[_AUTH_VAR])
            && $_SESSION[_AUTH_VAR]->isAdmin();
    }
}
