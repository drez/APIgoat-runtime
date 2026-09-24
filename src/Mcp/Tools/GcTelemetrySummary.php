<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

/**
 * telemetry_summary — aggregate view of the with_client_telemetry event
 * stream (client_event): totals by kind/platform, top event names, recent
 * errors with message + app/update version, timing percentiles-lite
 * (avg/max), and the version mix seen in the window. Raw rows stay the
 * job of crm_list on ClientEvent; this answers "how is the app doing"
 * in one call. Gated in ToolRegistry::builtins() on the generated
 * \App\ClientEventQuery existing (the table declares with_client_telemetry).
 */
class GcTelemetrySummary implements \ApiGoat\Mcp\McpTool
{
    public function name(): string { return 'telemetry_summary'; }

    public function description(): string
    {
        return 'Client-telemetry rollup for a time window: event totals by kind/platform, '
             . 'top event names, recent errors (message + app/update version), timing '
             . 'avg/max, and the version mix. Use crm_list on ClientEvent for raw rows.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'hours'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'default' => 24,
                'description' => 'Window size in hours (default 24, max 30 days)'],
            'kind'     => ['type' => 'string', 'enum' => ['error', 'timing', 'action'],
                'description' => 'Only this event kind'],
            'platform' => ['type' => 'string', 'description' => "Only this platform (e.g. 'ios', 'android', 'web')"],
            'name'     => ['type' => 'string', 'description' => "Only matching event names; '%' = LIKE"],
            'errors_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10,
                'description' => 'How many recent error rows to include'],
        ]];
    }

    /** Session must be able to read ClientEvent; hides the tool otherwise. */
    public function requiredRight(): ?array { return ['ClientEvent', 'r']; }

    /**
     * Row scope for the raw rollup SQL, mirroring AuthySession::applyTenantScope
     * + applyOwnerGroupScope (fail-closed when a needed scope column is absent).
     * Pure over the session + the Peer's column constants; public for tests.
     *
     * @param class-string $peer
     * @return array{0:list<string>,1:list<mixed>} [where fragments, params]
     */
    public static function scope(AuthySession $session, string $peer): array
    {
        if ($session->isRoot()) {
            return [[], []];
        }
        $where = [];
        $params = [];
        $has = static fn (string $c): bool => \defined($peer . '::' . $c);

        if ($has('ID_TENANT')) {
            $tenant = $session->get('id_tenant');
            if ($tenant) {
                $where[]  = 'id_tenant = ?';
                $params[] = $tenant;
            } elseif ($session->get('connected') == 'YES') {
                return [['1 = 0'], []];
            }
        }

        $scope = $session->hasRights('ClientEvent', 'r');
        if ($scope === true) {
            return [$where, $params];
        }
        if (!\is_array($scope)) {
            return [['1 = 0'], []];
        }
        $wantOwner = \in_array('Owner', $scope, true);
        $wantGroup = \in_array('Group', $scope, true);
        $groups = \array_values(\array_filter(\array_map('intval', (array) ($session->getGroups() ?? []))));
        $groupSql = null;
        if ($wantGroup && $has('ID_GROUP_CREATION') && $groups !== []) {
            $groupSql = 'id_group_creation IN (' . \implode(',', \array_fill(0, \count($groups), '?')) . ')';
        }
        if ($wantOwner) {
            if (!$has('ID_CREATION')) {
                return [['1 = 0'], []];
            }
            if ($groupSql !== null) {
                $where[] = '(id_creation = ? OR ' . $groupSql . ')';
                $params  = \array_merge($params, [(int) $session->getIdAuthy()], $groups);
            } else {
                $where[]  = 'id_creation = ?';
                $params[] = (int) $session->getIdAuthy();
            }
        } elseif ($wantGroup) {
            if ($groupSql === null) {
                return [['1 = 0'], []];
            }
            $where[] = $groupSql;
            $params  = \array_merge($params, $groups);
        } else {
            return [['1 = 0'], []];
        }
        return [$where, $params];
    }

    public function handle(array $args, AuthySession $session): array
    {
        $peer = '\\App\\ClientEventPeer';
        if (!\class_exists($peer)) {
            throw new ToolError('Client telemetry is not enabled in this project.', [], 'not_found');
        }
        $table = $peer::TABLE_NAME;
        /** @var array<int,string> $kindSet ordinal => label */
        $kindSet = $peer::getValueSet($peer::KIND);

        $hours = \max(1, \min(720, (int) ($args['hours'] ?? 24)));
        $since = \time() - $hours * 3600;

        $where  = ['created_at >= ?'];
        $params = [$since];
        if (isset($args['kind']) && $args['kind'] !== '') {
            $ord = \array_search((string) $args['kind'], $kindSet, true);
            if ($ord === false) {
                throw new ToolError("Unknown kind '{$args['kind']}'. One of: " . \implode(', ', $kindSet) . '.', [], 'validation');
            }
            $where[]  = 'kind = ?';
            $params[] = $ord;
        }
        if (isset($args['platform']) && $args['platform'] !== '') {
            $where[]  = 'platform = ?';
            $params[] = (string) $args['platform'];
        }
        if (isset($args['name']) && $args['name'] !== '') {
            $name = (string) $args['name'];
            if (\str_contains($name, '%')) {
                $where[] = 'name LIKE ?';
            } else {
                $where[] = 'name = ?';
            }
            $params[] = $name;
        }
        // Raw SQL bypasses the ORM tenant behavior and setAclFilter, so apply
        // the same tenant partition + Owner/Group row scope here explicitly.
        [$scopeWhere, $scopeParams] = self::scope($session, $peer);
        foreach ($scopeWhere as $sw) {
            $where[] = $sw;
        }
        $params = \array_merge($params, $scopeParams);
        $w = \implode(' AND ', $where);

        $con = \Propel::getConnection(\defined('_DATA_SRC') ? _DATA_SRC : null);
        $rows = function (string $sql, array $p) use ($con): array {
            $st = $con->prepare($sql);
            $st->execute($p);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        };
        $iso = static fn ($epoch): ?string => $epoch ? \date('Y-m-d H:i:s', (int) $epoch) : null;

        $byKind = [];
        foreach ($rows("SELECT kind, COUNT(*) n FROM {$table} WHERE {$w} GROUP BY kind", $params) as $r) {
            $byKind[$kindSet[(int) $r['kind']] ?? ('kind' . $r['kind'])] = (int) $r['n'];
        }

        $byPlatform = [];
        foreach ($rows("SELECT COALESCE(platform,'?') p, COUNT(*) n FROM {$table} WHERE {$w} GROUP BY platform", $params) as $r) {
            $byPlatform[$r['p']] = (int) $r['n'];
        }

        $topNames = [];
        foreach ($rows(
            "SELECT kind, name, COUNT(*) n, COUNT(DISTINCT id_authy) users, MAX(created_at) last_at
             FROM {$table} WHERE {$w} GROUP BY kind, name ORDER BY n DESC LIMIT 15",
            $params
        ) as $r) {
            $topNames[] = [
                'kind' => $kindSet[(int) $r['kind']] ?? ('kind' . $r['kind']),
                'name' => $r['name'], 'count' => (int) $r['n'],
                'users' => (int) $r['users'], 'last_seen' => $iso($r['last_at']),
            ];
        }

        $recentErrors = [];
        $errOrd = \array_search('error', $kindSet, true);
        if ($errOrd !== false) {
            $limit = \max(1, \min(50, (int) ($args['errors_limit'] ?? 10)));
            foreach ($rows(
                "SELECT name, message, platform, app_version, update_id, created_at
                 FROM {$table} WHERE {$w} AND kind = ? ORDER BY created_at DESC LIMIT {$limit}",
                \array_merge($params, [$errOrd])
            ) as $r) {
                $recentErrors[] = [
                    'name' => $r['name'],
                    'message' => \mb_substr((string) $r['message'], 0, 300),
                    'platform' => $r['platform'], 'app_version' => $r['app_version'],
                    'update_id' => $r['update_id'], 'at' => $iso($r['created_at']),
                ];
            }
        }

        $timings = [];
        $timOrd = \array_search('timing', $kindSet, true);
        if ($timOrd !== false) {
            foreach ($rows(
                "SELECT name, COUNT(*) n, ROUND(AVG(duration_ms)) avg_ms, MAX(duration_ms) max_ms
                 FROM {$table} WHERE {$w} AND kind = ? AND duration_ms IS NOT NULL
                 GROUP BY name ORDER BY n DESC LIMIT 10",
                \array_merge($params, [$timOrd])
            ) as $r) {
                $timings[] = ['name' => $r['name'], 'count' => (int) $r['n'],
                    'avg_ms' => (int) $r['avg_ms'], 'max_ms' => (int) $r['max_ms']];
            }
        }

        $versions = [];
        foreach ($rows(
            "SELECT COALESCE(app_version,'?') v, COALESCE(update_id,'') u, COUNT(*) n, COUNT(DISTINCT id_authy) users
             FROM {$table} WHERE {$w} GROUP BY app_version, update_id ORDER BY n DESC LIMIT 10",
            $params
        ) as $r) {
            $versions[] = ['app_version' => $r['v'], 'update_id' => $r['u'],
                'count' => (int) $r['n'], 'users' => (int) $r['users']];
        }

        $payload = [
            'window' => ['hours' => $hours, 'since' => $iso($since), 'until' => $iso(\time())],
            'total' => \array_sum($byKind),
            'by_kind' => $byKind,
            'by_platform' => $byPlatform,
            'top_names' => $topNames,
            'recent_errors' => $recentErrors,
            'timings' => $timings,
            'versions' => $versions,
        ];

        return [
            'content' => [['type' => 'text', 'text' => \json_encode($payload, JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
        ];
    }
}
