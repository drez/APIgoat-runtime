<?php

namespace ApiGoat\Ops;

/**
 * Basic MCP usage telemetry (with_mcp + with_ops_monitor): one hourly rollup
 * row per (tool, OAuth client) in ops_mcp_hour — calls, errors, denials,
 * total/max ms. McpServer times every tools/call (and counts the protocol
 * methods: initialize, tools/list) and hands the outcome here; the upsert
 * runs after the response (RequestRecorder::deferHook), and a project
 * without the table (with_mcp off, or not rebuilt yet) only logs.
 *
 * The client is the access token's `aud` (league/oauth2-server puts the
 * client_id there), read from the bearer the request already passed
 * validation with — so a token never has to be decoded twice for trust.
 */
final class McpRecorder
{
    public const OUTCOMES = ['ok', 'error', 'denied'];

    private static string $client = '';

    /** Remember this request's OAuth client (called once auth succeeded). */
    public static function setClientFromBearer(string $authorizationHeader): void
    {
        self::$client = self::clientFromBearer($authorizationHeader);
    }

    public static function client(): string
    {
        return self::$client;
    }

    public static function record(string $tool, int $ms, string $outcome, ?int $now = null): void
    {
        if (!Config::enabled() || !\in_array($outcome, self::OUTCOMES, true)) {
            return;
        }
        $row = [
            'hour'   => \intdiv($now ?? \time(), 3600) * 3600,
            'tool'   => \mb_substr($tool !== '' ? $tool : '(none)', 0, 64),
            'client' => self::$client,
            'ms'     => \max(0, $ms),
            'err'    => $outcome === 'error' ? 1 : 0,
            'denied' => $outcome === 'denied' ? 1 : 0,
            'ts'     => $now ?? \time(),
        ];
        RequestRecorder::deferHook(static function () use ($row): void {
            try {
                self::write(\Propel::getConnection(_DATA_SRC), $row);
            } catch (\Throwable $e) {
                \error_log('[ops] mcp record failed: ' . $e->getMessage());
            }
        });
    }

    /** The upsert itself (public for tests). */
    public static function write(\PDO $pdo, array $row): void
    {
        $pdo->prepare(
            'INSERT INTO ops_mcp_hour (hour, tool, client, n, n_err, n_denied, sum_ms, max_ms, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE n = n + 1, n_err = n_err + VALUES(n_err), n_denied = n_denied + VALUES(n_denied),
                sum_ms = sum_ms + VALUES(sum_ms), max_ms = GREATEST(max_ms, VALUES(max_ms))'
        )->execute([$row['hour'], $row['tool'], $row['client'], $row['err'], $row['denied'], $row['ms'], $row['ms'], $row['ts']]);
    }

    /** `aud` of a JWT bearer, '' when absent/unreadable. Pure. */
    public static function clientFromBearer(string $authorizationHeader): string
    {
        if (\stripos($authorizationHeader, 'Bearer ') !== 0) {
            return '';
        }
        $parts = \explode('.', \trim(\substr($authorizationHeader, 7)));
        if (\count($parts) !== 3) {
            return '';
        }
        $payload = \json_decode((string) \base64_decode(\strtr($parts[1], '-_', '+/'), true), true);
        $aud = \is_array($payload) ? ($payload['aud'] ?? '') : '';
        if (\is_array($aud)) {
            $aud = $aud[0] ?? '';
        }

        return \is_string($aud) ? \mb_substr($aud, 0, 80) : '';
    }
}
