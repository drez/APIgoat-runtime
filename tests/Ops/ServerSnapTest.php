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
 * Pure-logic coverage of ServerSnap::collect()'s branching (no source / no
 * snapshot / stored / swallowed failure) and store()'s SQL shape, using a
 * PDO mock — R1: this host has no pdo_sqlite, so the actual INSERT against a
 * real ops_server_snap table is tested against MySQL in
 * P/.admin/tests/Custom/OpsServerSnapTest.php instead.
 */
final class ServerSnapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        Config::reset();
        $this->dir = \sys_get_temp_dir() . '/server_snap_test_' . \uniqid();
        \mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        Config::reset();
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->dir);
    }

    private function writeSnapshot(array $overrides = []): string
    {
        $path = $this->dir . '/snap.json';
        \file_put_contents($path, \json_encode(\array_merge([
            'load1'      => 0.5,
            'mem_pct'    => 40.0,
            'disk_pct'   => 20.0,
            'services'   => ['nginx' => true],
            'f2b_banned' => 1,
            'f2b'        => ['sshd' => 1],
            'auth'       => ['ssh_failed' => 2, 'ssh_accepted' => 1, 'window_h' => 24],
            'at'         => \time(),
        ], $overrides)));

        return $path;
    }

    public function test_collect_returns_no_source_when_server_source_is_none(): void
    {
        Config::override(['server_source' => 'none', 'snapshot_path' => '']);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame('server snap: no source', ServerSnap::collect($pdo, \time()));
    }

    public function test_collect_returns_unavailable_when_the_snapshot_file_is_missing(): void
    {
        Config::override(['server_source' => 'snapshot', 'snapshot_path' => $this->dir . '/does-not-exist.json']);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $this->assertSame('server snap: unavailable', ServerSnap::collect($pdo, \time()));
    }

    public function test_collect_stores_a_valid_snapshot(): void
    {
        $path = $this->writeSnapshot();
        Config::override(['server_source' => 'snapshot', 'snapshot_path' => $path]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with($this->callback(function (array $params) {
            return $params[':load1'] === 0.5
                && $params[':mem_pct'] === 40.0
                && $params[':disk_pct'] === 20.0
                && $params[':services'] === \json_encode(['nginx' => true])
                && $params[':f2b_banned'] === 1
                && $params[':f2b'] === \json_encode(['sshd' => 1])
                && $params[':auth'] === \json_encode(['ssh_failed' => 2, 'ssh_accepted' => 1, 'window_h' => 24])
                && \is_int($params[':created_at']);
        }))->willReturn(true);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO ops_server_snap'))
            ->willReturn($stmt);

        $this->assertSame('server snap: stored', ServerSnap::collect($pdo, 12345));
    }

    public function test_collect_swallows_a_pdo_failure_and_returns_error(): void
    {
        $path = $this->writeSnapshot();
        Config::override(['server_source' => 'snapshot', 'snapshot_path' => $path]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException("Table 'ops_server_snap' doesn't exist"));

        $this->assertSame('server snap: error', ServerSnap::collect($pdo, \time()));
    }

    public function test_collect_never_throws_even_on_an_unexpected_error(): void
    {
        $path = $this->writeSnapshot();
        Config::override(['server_source' => 'snapshot', 'snapshot_path' => $path]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \RuntimeException('boom'));

        $result = ServerSnap::collect($pdo, \time());
        $this->assertSame('server snap: error', $result);
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
        ], \time());
    }
}
