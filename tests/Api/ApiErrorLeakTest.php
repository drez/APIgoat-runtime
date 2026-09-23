<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Api;

use ApiGoat\Api\Api;
use PHPUnit\Framework\TestCase;

/**
 * Wave 4 (2026-09-23): the generic CRUD catch blocks echoed
 * $exception->getMessage() to the client — a PropelException there carries
 * the full SQL statement and the wrapped SQLSTATE/PDO text (table/column
 * names, values). The client gets a generic label; the detail goes to the
 * server log. Only QueryBuilder's own caller-input diagnostics (unknown
 * column / model) pass through, since they name nothing the caller did not
 * send.
 */
final class ApiErrorLeakTest extends TestCase
{
    private string $log;
    private string $prevLog;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'apierr');
        $this->prevLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog);
        @unlink($this->log);
    }

    public function testSqlBearingExceptionIsNotEchoed(): void
    {
        $x = new \Exception('Unable to execute SELECT statement [SELECT authy.passwd_hash FROM `authy` WHERE x=1] [wrapped: SQLSTATE[42S22]: Column not found]');
        $out = Api::clientError('Invalid parameter 4', $x, 'Authy');

        $this->assertSame('Invalid parameter 4', $out);
        $this->assertStringNotContainsString('SELECT', $out);
        $this->assertStringContainsString('SQLSTATE[42S22]', (string) file_get_contents($this->log), 'detail is logged server-side');
    }

    public function testQueryBuilderInputDiagnosticsPassThrough(): void
    {
        $x = new \Exception('Normalize: Unknown column "Foo" on model, alias or table "Contact"');
        $this->assertSame('Invalid parameter 4: Normalize: Unknown column "Foo" on model, alias or table "Contact"', Api::clientError('Invalid parameter 4', $x, 'Contact'));

        $y = new \Exception('Unknown model, alias or table "Nope"');
        $this->assertSame('Invalid parameter 1: Unknown model, alias or table "Nope"', Api::clientError('Invalid parameter 1', $y, 'Contact'));
    }

    public function testPassThroughIsAnchoredNotSubstring(): void
    {
        $x = new \Exception('Unable to execute [SELECT 1] Unknown column "a" on model, alias or table "b"');
        $this->assertSame('Invalid parameter 2', Api::clientError('Invalid parameter 2', $x, 'Contact'));
    }

    public function testNoCatchSiteEchoesRawExceptionMessage(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Api/Api.php');
        $this->assertDoesNotMatchRegularExpression('/\\$ret\\[\'error\'\\]\\s*=\\s*"[^"]*"\\s*\\.\\s*\\$x->getMessage\\(\\)/', $src);
    }
}
