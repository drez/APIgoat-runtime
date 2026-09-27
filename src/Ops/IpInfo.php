<?php

namespace ApiGoat\Ops;

/**
 * ops_ip_info: a 30-day cache of what an IP is — its reverse-DNS name and its
 * RDAP (whois) owner + country — for the Security dashboard's IP columns.
 *
 * Lookups are slow (a PTR query plus an HTTPS call per IP), so they never
 * run while a page renders: Tick calls resolvePending() in the background,
 * and the dashboard only reads the cache through map(), showing the bare IP
 * until a row exists.
 *
 * A PTR record is set by whoever owns the IP, so a scanner can make its IP
 * "resolve" to any name it likes; the host is kept only when it is
 * forward-confirmed (the name resolves back to the same IP).
 *
 * Tolerates a project whose schema predates the table (built before
 * with_ops_monitor emitted it): map() returns [] and resolvePending() reports
 * 'ip info: no table'. Never throws.
 */
final class IpInfo
{
    /** Cache lifetime: an entry is re-resolved (and pruned) after 30 days. */
    public const TTL = 30 * 86400;

    private const RDAP_URL = 'https://rdap.org/ip/';

    /**
     * Cached rows for these IPs. Missing IPs are simply absent.
     *
     * @param list<string> $ips
     * @return array<string, array{host:?string, org:?string, country:?string}>
     */
    public static function map(\PDO $pdo, array $ips): array
    {
        $ips = \array_values(\array_unique(\array_filter($ips, static fn ($ip) => \is_string($ip) && $ip !== '')));
        if ($ips === []) {
            return [];
        }
        try {
            $stmt = $pdo->prepare('SELECT ip, host, org, country FROM ops_ip_info WHERE ip IN (' . \implode(',', \array_fill(0, \count($ips), '?')) . ')');
            $stmt->execute($ips);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['ip']] = [
                    'host'    => $r['host'] !== null && $r['host'] !== '' ? (string) $r['host'] : null,
                    'org'     => $r['org'] !== null && $r['org'] !== '' ? (string) $r['org'] : null,
                    'country' => $r['country'] !== null && $r['country'] !== '' ? (string) $r['country'] : null,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve up to $limit IPs seen in the last 30 days (security events,
     * login failures) that have no fresh cache row, newest first, stopping
     * early once $budget seconds are spent. $lookup is the test seam
     * (ip => [host, org, country]).
     */
    public static function resolvePending(\PDO $pdo, int $now, int $limit = 25, int $budget = 20, ?callable $lookup = null): string
    {
        try {
            if (!self::tableExists($pdo, 'ops_ip_info')) {
                return 'ip info: no table';
            }
            $pending = self::pending($pdo, $now, $limit);
            if ($pending === []) {
                return 'ip info: 0 resolved';
            }
            $lookup ??= [self::class, 'lookup'];
            $upsert = $pdo->prepare(
                'INSERT INTO ops_ip_info (ip, host, org, country, created_at, site_id) VALUES (?,?,?,?,?,0)
                 ON DUPLICATE KEY UPDATE host = VALUES(host), org = VALUES(org), country = VALUES(country), created_at = VALUES(created_at)'
            );
            $start = \microtime(true);
            $n = 0;
            foreach ($pending as $ip) {
                if (\microtime(true) - $start > $budget) {
                    break;
                }
                $info = $lookup($ip);
                $upsert->execute([
                    $ip,
                    self::clip($info['host'] ?? null, 255),
                    self::clip($info['org'] ?? null, 191),
                    self::country($info['country'] ?? null),
                    $now,
                ]);
                $n++;
            }

            return "ip info: {$n} resolved";
        } catch (\Throwable $e) {
            \error_log('[ops] ip info failed: ' . $e->getMessage());

            return 'ip info: error';
        }
    }

    /**
     * Live lookup of one IP. Private/reserved addresses are never sent out.
     *
     * @return array{host:?string, org:?string, country:?string}
     */
    public static function lookup(string $ip): array
    {
        if (\filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            return ['host' => null, 'org' => null, 'country' => null];
        }
        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['host' => null, 'org' => 'Private network', 'country' => null];
        }

        return ['host' => self::reverseDns($ip)] + self::rdap($ip);
    }

    /**
     * Owner + country out of an RDAP ip-network response: the registrant
     * entity's vCard name, else the network name. Pure.
     *
     * @param array<string,mixed> $j
     * @return array{org:?string, country:?string}
     */
    public static function parseRdap(array $j): array
    {
        $org = self::registrant($j['entities'] ?? []);
        if ($org === null && isset($j['name']) && \is_string($j['name']) && $j['name'] !== '') {
            $org = $j['name'];
        }
        $country = isset($j['country']) && \is_string($j['country']) ? $j['country'] : null;

        return ['org' => $org, 'country' => self::country($country)];
    }

    /** @return list<string> */
    private static function pending(\PDO $pdo, int $now, int $limit): array
    {
        $since = $now - self::TTL;
        $sources = ['SELECT ip, MAX(created_at) seen FROM ops_sec_event WHERE created_at >= ' . $since . ' AND ip IS NOT NULL AND ip <> \'\' GROUP BY ip'];
        if (self::tableExists($pdo, 'authy_log')) {
            $sources[] = 'SELECT ip, MAX(UNIX_TIMESTAMP(timestamp)) seen FROM authy_log WHERE timestamp >= FROM_UNIXTIME(' . $since . ') AND ip IS NOT NULL AND ip <> \'\' GROUP BY ip';
        }
        $sql = 'SELECT s.ip FROM (' . \implode(' UNION ALL ', $sources) . ') s
                LEFT JOIN ops_ip_info i ON i.ip = s.ip AND i.created_at >= ' . $since . '
                WHERE i.ip IS NULL
                GROUP BY s.ip
                ORDER BY MAX(s.seen) DESC
                LIMIT ' . \max(1, $limit);

        return \array_values(\array_filter(
            \array_map('strval', $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN)),
            static fn ($ip) => \filter_var($ip, \FILTER_VALIDATE_IP) !== false
        ));
    }

    /** PTR name, kept only when it resolves back to the same IP. */
    private static function reverseDns(string $ip): ?string
    {
        $host = @\gethostbyaddr($ip);
        if (!\is_string($host) || $host === '' || $host === $ip) {
            return null;
        }
        $host = \rtrim(\strtolower($host), '.');
        $isV6 = \str_contains($ip, ':');
        $records = @\dns_get_record($host, $isV6 ? \DNS_AAAA : \DNS_A);
        if (!\is_array($records)) {
            return null;
        }
        $want = \inet_pton($ip);
        foreach ($records as $r) {
            $addr = $r[$isV6 ? 'ipv6' : 'ip'] ?? null;
            if (\is_string($addr) && @\inet_pton($addr) === $want) {
                return $host;
            }
        }

        return null;
    }

    /** @return array{org:?string, country:?string} */
    private static function rdap(string $ip): array
    {
        $none = ['org' => null, 'country' => null];
        if (!\function_exists('curl_init')) {
            return $none;
        }
        $ch = \curl_init(self::RDAP_URL . \rawurlencode($ip));
        \curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS      => 3,
            \CURLOPT_PROTOCOLS      => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_CONNECTTIMEOUT => 3,
            \CURLOPT_TIMEOUT        => 5,
            \CURLOPT_HTTPHEADER     => ['Accept: application/rdap+json'],
            \CURLOPT_USERAGENT      => 'apigoat-ops/1.0',
        ]);
        $body = \curl_exec($ch);
        $code = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        \curl_close($ch);
        if (!\is_string($body) || $code !== 200) {
            return $none;
        }
        $j = \json_decode($body, true);

        return \is_array($j) ? self::parseRdap($j) : $none;
    }

    /** The first registrant's vCard "fn", searching nested entities too. */
    private static function registrant(mixed $entities, int $depth = 0): ?string
    {
        if (!\is_array($entities) || $depth > 3) {
            return null;
        }
        foreach ($entities as $ent) {
            if (!\is_array($ent)) {
                continue;
            }
            if (\in_array('registrant', (array) ($ent['roles'] ?? []), true)) {
                foreach ((array) ($ent['vcardArray'][1] ?? []) as $prop) {
                    if (\is_array($prop) && ($prop[0] ?? null) === 'fn' && \is_string($prop[3] ?? null) && $prop[3] !== '') {
                        return $prop[3];
                    }
                }
            }
            $nested = self::registrant($ent['entities'] ?? [], $depth + 1);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private static function clip(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = \trim(\preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');

        return $s === '' ? null : \mb_substr($s, 0, $max);
    }

    private static function country(?string $c): ?string
    {
        return \is_string($c) && \preg_match('/^[A-Za-z]{2}$/', $c) ? \strtoupper($c) : null;
    }

    private static function tableExists(\PDO $pdo, string $table): bool
    {
        return (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
    }
}
