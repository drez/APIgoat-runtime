<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Config;
use ApiGoat\Ops\SecEvent;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Config.php';
require_once __DIR__ . '/../../src/Ops/SecEvent.php';

/**
 * Pure-logic coverage only (R1): this host has no pdo_sqlite, so the actual
 * DB insert (`SecEvent::write()` against a real ops_sec_event table) is
 * tested against MySQL in
 * P/.admin/tests/Custom/OpsSecEventTest.php instead. The task brief's own
 * Step 1 write()/unknown-type/mcpDetail examples happen to use a
 * sqlite::memory: PDO, which this host cannot construct — those same
 * assertions are exercised here with a PDO mock (write()'s SQL shape) and
 * against the real ops_sec_event table in the DB-backed test. What's
 * testable here without a database: TYPES, the unknown-type guard (thrown
 * BEFORE any PDO is touched), mcpDetail(), detail truncation, and
 * record()'s no-op-when-disabled / swallow-on-failure contract.
 */
final class SecEventTest extends TestCase
{
    private $prevLog;
    private string $logFile;

    protected function setUp(): void
    {
        Config::reset();
        $this->logFile = \tempnam(\sys_get_temp_dir(), 'secevent');
        $this->prevLog = \ini_get('error_log');
        \ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->prevLog);
        if (\is_file($this->logFile)) {
            \unlink($this->logFile);
        }
        Config::reset();
    }

    public function test_types_lists_every_declared_event(): void
    {
        $expected = [
            'rbac_deny', 'csrf', 'stale_session', 'access_denied', 'switch_rejected',
            'reauth_throttled', 'token_reuse', 'token_revoked', 'jwt_refused',
            'mcp_call', 'google_login', 'google_reject',
        ];
        \sort($expected);
        $actual = SecEvent::TYPES;
        \sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_unknown_type_throws_before_touching_the_pdo(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->expectException(\InvalidArgumentException::class);
        SecEvent::write($pdo, 'nope', null, null, '', 0);
    }

    public function test_write_inserts_with_the_expected_shape(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (array $params): bool {
                return $params[':type'] === 'mcp_call'
                    && $params[':id_authy'] === 5
                    && $params[':ip'] === '9.9.9.9'
                    && \strlen((string) $params[':detail']) === 512
                    && $params[':created_at'] === 100;
            }))
            ->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO ops_sec_event'))
            ->willReturn($stmt);

        SecEvent::write($pdo, 'mcp_call', 5, '9.9.9.9', \str_repeat('x', 900), 100);
    }

    public function test_write_detail_untouched_when_under_the_limit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(fn (array $p) => $p[':detail'] === 'short detail'))
            ->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        SecEvent::write($pdo, 'mcp_call', null, null, 'short detail', 1);
    }

    public function test_write_id_authy_and_ip_may_be_null(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(fn (array $p) => $p[':id_authy'] === null && $p[':ip'] === null))
            ->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        SecEvent::write($pdo, 'google_login', null, null, '', 1);
    }

    public function test_mcp_detail_never_contains_args(): void
    {
        $this->assertSame('accx_add_time ok', SecEvent::mcpDetail('accx_add_time', null));
        $this->assertSame('accx_add_time error:validation', SecEvent::mcpDetail('accx_add_time', 'validation'));
    }

    public function test_record_is_a_no_op_when_ops_monitor_is_disabled(): void
    {
        // No _BASE_DIR defined anywhere in this process (see ConfigTest's own
        // note) => Config::enabled() is false => record() must not even try
        // to reach Propel::getConnection(), which is undefined in this
        // bootstrap and would otherwise fatal.
        $this->assertFalse(Config::enabled());

        SecEvent::record('mcp_call', null, null, 'accx_add_time ok');
        $this->addToAssertionCount(1); // reaching here without a fatal is the assertion
    }

    public function test_record_still_throws_on_an_unknown_type_even_when_disabled(): void
    {
        // A programming error (unknown type) must not be swallowed by the
        // enabled() gate — validated before the try/catch.
        $this->assertFalse(Config::enabled());

        $this->expectException(\InvalidArgumentException::class);
        SecEvent::record('not-a-real-type');
    }
}
