<?php

namespace ApiGoat\Realtime;

/**
 * Short-lived signed handshake ticket for the realtime WebSocket.
 *
 * A browser cannot set an Authorization header on a WebSocket handshake, and
 * teaching the sidecar to read PHP session files would drag AuthySession
 * deserialization (and with it the app) into a process that must stay dumb.
 * Instead the FPM app — which already knows who the caller is — mints a ticket
 * signed with the project's JWT_SECRET, and the sidecar verifies the signature
 * with no database and no session access.
 *
 * The ticket conveys identity (authy id + tenant) plus the DB tables the user
 * may read (hasRights 'r', mapped model -> {Model}Peer::TABLE_NAME; '*' for Admin/root).
 * It grants no row access: the sidecar pushes table names, never rows, but the
 * table list stops a socket from learning WHEN a table it cannot read changes.
 *
 * Format: base64url(json payload) . '.' . base64url(hmac-sha256)
 *
 * Signing key: HKDF-SHA256(JWT_SECRET, info 'gc-realtime-ticket-v1') — a key
 * dedicated to this purpose, never the raw JWT_SECRET that also signs app
 * JWTs. Transition: a ticket signed with the raw JWT_SECRET (runtime before
 * 2026-09-23) is still accepted during a short window opened by the sidecar at
 * boot (openLegacyWindow), so tickets minted by the old web code right before
 * a restart still connect. The FPM side never opens it.
 */
final class Ticket
{
    /** Seconds a freshly minted ticket stays valid. Deliberately short: it is
     *  used once, immediately, to open a socket. */
    public const TTL = 30;

    /** HKDF info string: bumping it invalidates every outstanding ticket. */
    private const KEY_INFO = 'gc-realtime-ticket-v1';

    /** Hard cap on the tables a ticket may carry (keeps the ws URL well under
     *  Apache's 8190-byte request line). Extra tables are dropped: fail-closed. */
    public const MAX_TABLES = 200;

    /** Unix time until which raw-JWT_SECRET (legacy) signatures verify. 0 = never. */
    private static int $legacyUntil = 0;

    /**
     * Accept legacy (raw JWT_SECRET) signatures for $seconds from now. Called
     * once by the sidecar at boot; a legacy ticket expires TTL seconds after it
     * was minted anyway, so 2*TTL covers every ticket the old code could have
     * handed out.
     */
    public static function openLegacyWindow(int $seconds = 2 * self::TTL): void
    {
        self::$legacyUntil = $seconds > 0 ? \time() + $seconds : 0;
    }

    /**
     * Mint a ticket for the currently connected user.
     *
     * @return string '' when nobody is connected or no secret is configured.
     */
    public static function mint(?int $authyId = null, ?string $tenant = null, ?array $tables = null): string
    {
        $key = self::key();
        if ($key === '') {
            return '';
        }

        if ($authyId === null) {
            $authyId = self::sessionAuthyId();
        }
        if ($authyId === null || $authyId <= 0) {
            return '';
        }
        if ($tenant === null) {
            $tenant = self::sessionTenant();
        }
        if ($tables === null) {
            $tables = self::sessionTables();
        }

        return self::sign([
            'u'  => $authyId,
            'tn' => self::normTenant($tenant),
            'tb' => self::normTables($tables),
            'e'  => \time() + self::TTL,
        ], $key);
    }

    /**
     * Verify a ticket.
     *
     * 'tb' is the list of readable DB tables, or ['*'] for unrestricted.
     *
     * @return array{u:int,tn:string,tb:list<string>,e:int}|null null when
     *         malformed, tampered with, expired, or when no secret is configured.
     */
    public static function verify(string $ticket): ?array
    {
        $key = self::key();
        if ($key === '' || $ticket === '') {
            return null;
        }

        $parts = \explode('.', $ticket);
        if (\count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;

        // Constant-time: a timing oracle on the signature is a forgery oracle.
        $legacy = false;
        if (!\hash_equals(self::b64e(\hash_hmac('sha256', $body, $key, true)), $sig)) {
            if (self::$legacyUntil < \time()
                || !\hash_equals(self::b64e(\hash_hmac('sha256', $body, self::secret(), true)), $sig)) {
                return null;
            }
            $legacy = true;
        }

        $raw = self::b64d($body);
        if ($raw === false) {
            return null;
        }
        $payload = \json_decode($raw, true);
        if (!\is_array($payload) || !isset($payload['u'], $payload['e'])) {
            return null;
        }
        if ((int) $payload['e'] < \time()) {
            return null;
        }

        if (isset($payload['tb']) && \is_array($payload['tb'])) {
            $tables = self::normTables($payload['tb']);
        } elseif ($legacy) {
            // Pre-2026-09-23 tickets carried no table list; they were allowed
            // every table. Only reachable inside the boot-time legacy window.
            $tables = ['*'];
        } else {
            $tables = [];
        }

        return [
            'u'  => (int) $payload['u'],
            // A ticket without a tenant never widens to 'all' (fail-closed).
            'tn' => self::normTenant(isset($payload['tn']) ? (string) $payload['tn'] : ''),
            'tb' => $tables,
            'e'  => (int) $payload['e'],
        ];
    }

    /** '' (no tenant) becomes 'tnone' — TableVersion::tenantToken()'s token for
     *  a tenant-less non-root user — never 'all'. */
    private static function normTenant(string $tn): string
    {
        return $tn === '' ? 'tnone' : $tn;
    }

    /**
     * @param array<mixed> $tables
     * @return list<string>
     */
    private static function normTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            if (!\is_string($t) || $t === '' || \strlen($t) > 128) {
                continue;
            }
            if ($t === '*') {
                return ['*'];
            }
            $out[$t] = true;
            if (\count($out) >= self::MAX_TABLES) {
                break;
            }
        }
        return \array_keys($out);
    }

    /** True when the verified claims allow subscribing to $table. */
    public static function allowsTable(array $claims, string $table): bool
    {
        $tb = $claims['tb'] ?? [];
        if (!\is_array($tb)) {
            return false;
        }
        return \in_array('*', $tb, true) || \in_array($table, $tb, true);
    }

    /**
     * DB tables the session user may read. Admin group / root => ['*'].
     * Models come from AuthySession::$accessControl (the same map hasRights
     * reads); a model whose Peer is missing is skipped.
     *
     * @return list<string>
     */
    private static function sessionTables(): array
    {
        if (!\defined('_AUTH_VAR') || !isset($_SESSION[\_AUTH_VAR]) || !\is_object($_SESSION[\_AUTH_VAR])) {
            return [];
        }
        $s = $_SESSION[\_AUTH_VAR];
        if (\method_exists($s, 'isRoot') && $s->isRoot()) {
            return ['*'];
        }
        if (!\method_exists($s, 'hasRights')) {
            return [];
        }
        // Admin group: hasRights() short-circuits true for any model.
        if (\method_exists($s, 'isAdmin') && $s->isAdmin()) {
            return ['*'];
        }
        $models = (isset($s->accessControl) && \is_array($s->accessControl)) ? \array_keys($s->accessControl) : [];
        $out = [];
        foreach ($models as $model) {
            if (!\is_string($model) || $model === '' || !$s->hasRights($model, 'r')) {
                continue;
            }
            $peer = '\\App\\' . $model . 'Peer';
            if (\class_exists($peer) && \defined($peer . '::TABLE_NAME')) {
                $out[] = (string) \constant($peer . '::TABLE_NAME');
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $payload */
    private static function sign(array $payload, string $key): string
    {
        $body = self::b64e((string) \json_encode($payload));
        return $body . '.' . self::b64e(\hash_hmac('sha256', $body, $key, true));
    }

    /** Purpose-bound ticket key derived from JWT_SECRET; '' when unset. */
    public static function key(): string
    {
        $secret = self::secret();
        if ($secret === '') {
            return '';
        }
        return \hash_hkdf('sha256', $secret, 32, self::KEY_INFO);
    }

    /**
     * The signing key. Read from the environment rather than the Configuration
     * service so the sidecar — which never boots the app container — can use
     * the exact same code path.
     */
    public static function secret(): string
    {
        $v = \function_exists('env') ? env('JWT_SECRET') : \getenv('JWT_SECRET');
        return \is_string($v) ? $v : '';
    }

    private static function sessionAuthyId(): ?int
    {
        if (!\defined('_AUTH_VAR') || !isset($_SESSION[\_AUTH_VAR]) || !\is_object($_SESSION[\_AUTH_VAR])) {
            return null;
        }
        $s = $_SESSION[\_AUTH_VAR];
        if (!\method_exists($s, 'get') || $s->get('connected') !== 'YES') {
            return null;
        }
        return (int) $s->get('id') ?: null;
    }

    /**
     * Tenant token, mirroring TableVersion::tenantToken() VERBATIM so a
     * subscriber's token compares equal to the one a writer stamps on a signal.
     */
    private static function sessionTenant(): string
    {
        if (\class_exists(\ApiGoat\Utility\TableVersion::class)) {
            return \ApiGoat\Utility\TableVersion::tenantToken();
        }
        return 'all';
    }

    private static function b64e(string $bin): string
    {
        return \rtrim(\strtr(\base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return string|false */
    private static function b64d(string $s)
    {
        return \base64_decode(\strtr($s, '-_', '+/'), true);
    }
}
