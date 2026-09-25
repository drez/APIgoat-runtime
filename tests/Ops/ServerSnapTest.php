<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\Config;
use ApiGoat\Ops\ServerSnap;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Config.php';
require_once __DIR__ . '/../../src/Ops/Server/Source.php';
require_once __DIR__ . '/../../src/Ops/Server/SnapshotFileSource.php';
require_once __DIR__ . '/../../src/Ops/Server/Factory.php';
require_once __DIR__ . '/../../src/Ops/ServerSnap.php';

/**
 * Pure-logic coverage only (R1: no pdo_sqlite on this host) — and, per R16
 * (controller ruling, fix round 1, item 5), collect() now gates on
 * Config::enabled() FIRST, exactly like CronLog::record()/SecEvent::record().
 * No _BASE_DIR is ever defined anywhere in this test process (see
 * ConfigTest's docblock — ServerSnapTest shares that invariant), so
 * Config::enabled() is always false here and every branch PAST that gate
 * (source selection, the snapshot itself, the stale-dedup check, the actual
 * INSERT, the swallowed-PDO-failure path) is unreachable from this suite —
 * exactly as CronLogTest only covers CronLog::record()'s no-op-when-disabled
 * contract, not its actual insert. Those branches are exercised for real,
 * against a project that actually declares with_ops_monitor (enabled() is
 * genuinely true there), in P/.admin/tests/Custom/OpsServerSnapTest.php.
 *
 * What's still fully unit-testable here without a database: the
 * disabled-short-circuit contract, and store()'s SQL/param shape + that it
 * does NOT swallow a PDO failure (store() is the inner, ungated half —
 * same split as SecEvent::write()/record()).
 */
final class ServerSnapTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_ops_monitor_is_disabled_in_this_process(): void
    {
        $this->assertFalse(\defined('_BASE_DIR'), 'a prior test defined _BASE_DIR; this assertion is no longer meaningful');
        $this->assertFalse(Config::enabled());
    }

    public function test_collect_returns_disabled_and_never_touches_the_pdo(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame('server snap: disabled', ServerSnap::collect($pdo));
    }

    /**
     * Config::override() only changes what get()/all() return — it can
     * never fake enabled() (that stays tied to the real manifest file, see
     * Config's own docblock) — so even a full 'snapshot' override must
     * still short-circuit to 'disabled' here.
     */
    public function test_collect_stays_disabled_even_with_a_full_config_override(): void
    {
        Config::override(['server_source' => 'snapshot', 'snapshot_path' => '/tmp/whatever.json']);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame('server snap: disabled', ServerSnap::collect($pdo));
    }

    public function test_store_inserts_with_the_expected_shape_using_the_snapshots_own_at(): void
    {
        // R16 item 4: created_at is $snap['at'], never a separately-passed
        // "now" — store() no longer even accepts one.
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with($this->callback(function (array $params) {
            return $params[':load1'] === 0.5
                && $params[':mem_pct'] === 40.0
                && $params[':disk_pct'] === 20.0
                && $params[':services'] === \json_encode(['nginx' => true])
                && $params[':f2b_banned'] === 1
                && $params[':f2b'] === \json_encode(['sshd' => 1])
                && $params[':auth'] === \json_encode(['ssh_failed' => 2, 'ssh_accepted' => 1, 'window_h' => 24])
                && $params[':created_at'] === 999888;
        }))->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO ops_server_snap'))
            ->willReturn($stmt);

        ServerSnap::store($pdo, [
            'load1'      => 0.5,
            'mem_pct'    => 40.0,
            'disk_pct'   => 20.0,
            'services'   => ['nginx' => true],
            'f2b_banned' => 1,
            'f2b'        => ['sshd' => 1],
            'auth'       => ['ssh_failed' => 2, 'ssh_accepted' => 1, 'window_h' => 24],
            'at'         => 999888,
        ]);
    }

    public function test_store_does_not_swallow_a_pdo_failure(): void
    {
        // store() is the un-guarded half (mirrors SecEvent::write vs
        // ::record) — collect() is the boundary that catches.
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('boom'));

        $this->expectException(\PDOException::class);
        ServerSnap::store($pdo, [
            'load1' => 0.1, 'mem_pct' => 1.0, 'disk_pct' => 1.0,
            'services' => [], 'f2b_banned' => 0, 'f2b' => [],
            'auth' => ['ssh_failed' => 0, 'ssh_accepted' => 0, 'window_h' => 24],
            'at' => 123,
        ]);
    }
}
