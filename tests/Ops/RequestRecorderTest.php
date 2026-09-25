<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\RequestRecorder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/Config.php';
require_once __DIR__ . '/../../src/Ops/RequestRecorder.php';

/**
 * Pure-logic coverage only (R1): this host has no pdo_sqlite, so the
 * upsert/slow-row/path-stripping behavior that needs a real database is
 * tested against MySQL in P/.admin/tests/Custom/OpsRequestRecorderTest.php
 * instead. What's testable here without a DB: the two static helpers that
 * decide the SQL's shape (bucket, routeKey), currentAuthyId()'s defensive
 * session reads, and that safeRecord() truly swallows a DB failure.
 */
final class RequestRecorderTest extends TestCase
{
    public function test_buckets(): void
    {
        $this->assertSame('b100', RequestRecorder::bucket(0));
        $this->assertSame('b100', RequestRecorder::bucket(100));
        $this->assertSame('b250', RequestRecorder::bucket(101));
        $this->assertSame('b250', RequestRecorder::bucket(250));
        $this->assertSame('b500', RequestRecorder::bucket(251));
        $this->assertSame('b1000', RequestRecorder::bucket(501));
        $this->assertSame('b2500', RequestRecorder::bucket(1001));
        $this->assertSame('b2500', RequestRecorder::bucket(2500));
        $this->assertSame('b_inf', RequestRecorder::bucket(2501));
    }

    public function test_unmatched_route_collapses(): void
    {
        $this->assertSame('(unmatched)', RequestRecorder::routeKey(null));
        $this->assertSame('(unmatched)', RequestRecorder::routeKey(''));
        $this->assertSame('/Client/{id}', RequestRecorder::routeKey('/Client/{id}'));
        $this->assertSame(191, \strlen(RequestRecorder::routeKey(\str_repeat('a', 400))));
    }

    /**
     * safeRecord() must not let a DB failure escape. A real \PDO against a
     * database with none of the ops_* tables is enough to force record() to
     * throw (no pdo_sqlite here, so this exercises the swallow path against
     * whatever default PDO driver behavior a bad prepare()/execute() has —
     * a mock is more direct and driver-independent).
     */
    public function test_record_swallows_db_errors(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('no such table: ops_req_hour'));

        RequestRecorder::safeRecord($pdo, [
            'route' => '/x', 'method' => 'GET', 'path' => '/x', 'status' => 200,
            'ms' => 1, 'queries' => 0, 'id_authy' => null, 'ip' => '', 'ts' => 0,
        ], 1000);

        $this->addToAssertionCount(1); // reaching here means no exception escaped
    }

    public function test_record_rethrows_are_not_expected_from_record_itself(): void
    {
        // record() (unlike safeRecord()) is allowed to let a DB error
        // propagate — safeRecord() is the boundary, not record().
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('boom'));

        $this->expectException(\PDOException::class);
        RequestRecorder::record($pdo, [
            'route' => '/x', 'method' => 'GET', 'path' => '/x', 'status' => 200,
            'ms' => 1, 'queries' => 0, 'id_authy' => null, 'ip' => '', 'ts' => 0,
        ], 1000);
    }

    public function test_current_authy_id_defensive_when_no_session(): void
    {
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'gc_ops_test_auth');
        }
        unset($_SESSION[\_AUTH_VAR]);

        $this->assertNull(RequestRecorder::currentAuthyId());
    }

    public function test_current_authy_id_reads_the_session_object(): void
    {
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'gc_ops_test_auth');
        }
        $_SESSION[\_AUTH_VAR] = new class {
            public function getIdAuthy(): int
            {
                return 42;
            }
        };

        $this->assertSame(42, RequestRecorder::currentAuthyId());

        unset($_SESSION[\_AUTH_VAR]);
    }

    public function test_current_authy_id_null_when_session_object_has_no_getter(): void
    {
        if (!\defined('_AUTH_VAR')) {
            \define('_AUTH_VAR', 'gc_ops_test_auth');
        }
        $_SESSION[\_AUTH_VAR] = new class {
        };

        $this->assertNull(RequestRecorder::currentAuthyId());

        unset($_SESSION[\_AUTH_VAR]);
    }
}
