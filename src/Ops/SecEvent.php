<?php

namespace ApiGoat\Ops;

/**
 * Writes one row to ops_sec_event (emitted by with_ops_monitor, Task 1):
 * the security event stream behind the Security dashboard — RBAC denials,
 * CSRF rejections, stale-session sign-outs, access denials, impersonation
 * (iarc) switch rejections, re-auth throttling, OAuth refresh-token reuse
 * / family revocation, JWT refusals, MCP tool calls and Google sign-in
 * outcomes.
 *
 * Mirror of RequestRecorder's safety contract: a request must never break
 * because of telemetry. record() is the entry point every call site uses —
 * it no-ops when with_ops_monitor isn't declared (Config::enabled()), and a
 * DB failure is swallowed to error_log. The one exception is a genuinely
 * unknown $type: that is a programming error (a call site typo'd a type, or
 * a new one was added at a call site without adding it to TYPES), so it is
 * validated and thrown BEFORE either the enabled() gate or the try/catch —
 * it must not be swallowed like a runtime/DB failure would be.
 *
 * No PII beyond id_authy + ip: $detail is caller-supplied free text but
 * every call site in this codebase only ever passes a short fixed reason
 * string (never a password, token, email or tool argument) — enforced by
 * review, not by this class, since SecEvent has no way to know what a
 * caller's string means.
 *
 * Transactions: record() writes on the request's shared Propel connection,
 * so an event recorded while a transaction is open on it commits or ROLLS
 * BACK with that transaction — an event recorded just before a rollback is
 * lost with it.
 */
final class SecEvent
{
    /** Every event type a call site may record. Keep in sync with the call sites below. */
    public const TYPES = [
        'rbac_deny',
        'csrf',
        'stale_session',
        'access_denied',
        'switch_rejected',
        'reauth_throttled',
        'token_reuse',
        'token_revoked',
        'jwt_refused',
        'mcp_call',
        'google_login',
        'google_reject',
    ];

    /** ops_sec_event.detail column width; write() truncates to this, never fails on an oversized caller string. */
    private const DETAIL_MAX = 512;

    /**
     * The safe, request-facing entry point. No-op when with_ops_monitor
     * isn't declared for this project; otherwise gets the Propel connection
     * and calls write(), with any \Throwable (bad connection, missing
     * table, …) swallowed to error_log so telemetry can never break the
     * request it is describing.
     *
     * An unknown $type is validated (see class docblock) before either of
     * those things happen, so it always throws — even when ops-monitor is
     * disabled — because it signals a bug at the call site, not a runtime
     * condition.
     */
    public static function record(string $type, ?int $idAuthy = null, ?string $ip = null, string $detail = ''): void
    {
        self::assertKnownType($type);

        if (!Config::enabled()) {
            return;
        }

        try {
            $pdo = \Propel::getConnection(_DATA_SRC);
            self::write($pdo, $type, $idAuthy, $ip, $detail, \time());
        } catch (\Throwable $e) {
            \error_log('[ops] sec event failed: ' . $e->getMessage());
        }
    }

    /**
     * The DB write itself, PDO injected so it can be tested without Propel
     * (and, per R1, without pdo_sqlite on this host — the DB-backed test
     * runs against a real MySQL connection in the project). Never swallows:
     * record() is the boundary that does that.
     *
     * $ip defaults to $_SERVER['REMOTE_ADDR'] when null; $detail is
     * truncated to the column width.
     */
    public static function write(\PDO $pdo, string $type, ?int $idAuthy, ?string $ip, string $detail, int $now): void
    {
        self::assertKnownType($type);

        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ip = $ip === '' ? null : \substr($ip, 0, 45);
        $detail = \substr($detail, 0, self::DETAIL_MAX);

        $stmt = $pdo->prepare(
            'INSERT INTO ops_sec_event (type, id_authy, ip, detail, created_at)
             VALUES (:type, :id_authy, :ip, :detail, :created_at)'
        );
        $stmt->execute([
            ':type'       => $type,
            // 0 is never a real authy row (FK) — store it as anonymous.
            ':id_authy'   => ($idAuthy !== null && $idAuthy > 0) ? $idAuthy : null,
            ':ip'         => $ip,
            ':detail'     => $detail,
            ':created_at' => $now,
        ]);
    }

    /**
     * A caller's user id as ops_sec_event.id_authy expects it: null for an
     * anonymous or unknown user. The session getters return null/'' for a
     * signed-out session, and `(int) null` is 0 — which is no authy row, so
     * the insert failed on the FK and the event was lost (final review I3).
     *
     * @param mixed $id
     */
    public static function authyId($id): ?int
    {
        if (\is_int($id)) {
            return $id > 0 ? $id : null;
        }
        if (\is_string($id) && \ctype_digit($id)) {
            return ((int) $id) > 0 ? (int) $id : null;
        }

        return null;
    }

    /**
     * The `mcp_call` detail string: "<tool> ok" on success, "<tool>
     * error:<kind>" on failure. Never includes tool arguments — the only
     * inputs are the tool's own name (not user data) and a short error
     * classification.
     */
    public static function mcpDetail(string $tool, ?string $errorKind): string
    {
        return $errorKind === null ? "{$tool} ok" : "{$tool} error:{$errorKind}";
    }

    private static function assertKnownType(string $type): void
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown ops_sec_event type '{$type}'");
        }
    }
}
