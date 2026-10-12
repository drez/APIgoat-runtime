<?php

use ApiGoat\Ops\IpInfo;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/IpInfo.php';

/**
 * apigoatacc rollout 2026-10-12: every prod logged, every ops tick,
 *   [ops] ip info failed: SQLSTATE[HY000]: General error: 1271 Illegal mix of
 *   collations for operation 'UNION'
 * IpInfo::pending() UNIONs authy_log.ip (project schema: utf8mb4_unicode_ci)
 * with ops_sec_event.ip (created by the runtime under the server default:
 * utf8mb4_general_ci on the prods) and joins the result to ops_ip_info.ip.
 * Dev boxes have one default for both, so it never reproduced locally.
 *
 * The live test rebuilds the prod shape with TEMPORARY tables (they shadow the
 * real ones for this connection only and vanish with it). It needs a
 * MariaDB/MySQL connection:
 *   GC_TEST_DB_DSN='mysql:host=127.0.0.1;dbname=…' GC_TEST_DB_USER=… GC_TEST_DB_PASS=…
 * and is skipped without one. The SQL-shape test always runs.
 */
final class IpInfoCollationTest extends TestCase
{
    private function pending(\PDO $pdo, int $now): array
    {
        $m = new ReflectionMethod(IpInfo::class, 'pending');
        $m->setAccessible(true);
        return $m->invoke(null, $pdo, $now, 10);
    }

    public function testEveryIpInTheUnionAndTheJoinIsCoercedToOneCollation(): void
    {
        $seen = [];
        $pdo = new class ($seen) extends \PDO {
            public function __construct(public array &$seen) {}
            public function quote($s, $t = \PDO::PARAM_STR): string|false { return "'" . $s . "'"; }
            public function query(string $sql, ...$a): \PDOStatement|false
            {
                $this->seen[] = $sql;
                return new class ($sql) extends \PDOStatement {
                    public function __construct(private string $sql) {}
                    public function fetchColumn(int $c = 0): mixed { return str_starts_with($this->sql, 'SHOW TABLES') ? 'authy_log' : false; }
                    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, ...$args): array { return []; }
                };
            }
        };
        $this->assertSame([], $this->pending($pdo, 1_700_000_000));
        $sql = end($pdo->seen);
        $this->assertStringContainsString('UNION ALL', $sql);
        $this->assertSame(3, substr_count($sql, 'COLLATE utf8mb4_bin'), 'both UNION branches and the ops_ip_info join: ' . $sql);
        $this->assertSame(3, substr_count($sql, 'CONVERT('), 'charset-safe coercion on every side');
    }

    public function testPendingSurvivesMixedCollationsOnARealServer(): void
    {
        $dsn = getenv('GC_TEST_DB_DSN');
        if (!$dsn) {
            $this->markTestSkipped('needs GC_TEST_DB_DSN (MariaDB/MySQL)');
        }
        $pdo = new \PDO($dsn, getenv('GC_TEST_DB_USER') ?: null, getenv('GC_TEST_DB_PASS') ?: null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $now = time();
        $pdo->exec('CREATE TEMPORARY TABLE ops_sec_event (ip VARCHAR(45) COLLATE utf8mb4_general_ci, created_at INT) CHARSET utf8mb4 COLLATE utf8mb4_general_ci');
        $pdo->exec('CREATE TEMPORARY TABLE ops_ip_info (ip VARCHAR(45) COLLATE utf8mb4_general_ci, created_at INT) CHARSET utf8mb4 COLLATE utf8mb4_general_ci');
        $pdo->exec('CREATE TEMPORARY TABLE authy_log (ip VARCHAR(16) COLLATE utf8mb4_unicode_ci, `timestamp` DATETIME) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec("INSERT INTO ops_sec_event VALUES ('203.0.113.9', {$now}), ('203.0.113.9', " . ($now - 10) . "), ('198.51.100.7', {$now})");
        $pdo->exec("INSERT INTO authy_log VALUES ('192.0.2.5', NOW()), ('203.0.113.9', NOW())");
        $pdo->exec("INSERT INTO ops_ip_info VALUES ('198.51.100.7', {$now})"); // already resolved → excluded

        $got = $this->pending($pdo, $now);
        sort($got);
        $this->assertSame(['192.0.2.5', '203.0.113.9'], $got);
    }
}
