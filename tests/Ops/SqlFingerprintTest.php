<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ops;

use ApiGoat\Ops\SqlFingerprint;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ops/SqlFingerprint.php';

final class SqlFingerprintTest extends TestCase
{
    public function test_literals_stripped(): void
    {
        $this->assertSame(
            'SELECT * FROM authy WHERE email = ? AND id > ?',
            SqlFingerprint::normalize("SELECT *  FROM authy\n WHERE email = 'a@b.c' AND id > 42")
        );
    }

    public function test_double_quoted_literal_stripped(): void
    {
        $this->assertSame(
            'SELECT ? FROM x',
            SqlFingerprint::normalize('SELECT "hi there" FROM x')
        );
    }

    public function test_doubled_quote_escape_inside_string_literal_is_consumed(): void
    {
        $this->assertSame(
            "UPDATE x SET name = ? WHERE id = ?",
            SqlFingerprint::normalize("UPDATE x SET name = 'it''s here' WHERE id = 7")
        );
    }

    public function test_negative_and_decimal_numbers_stripped(): void
    {
        $this->assertSame(
            'SELECT * FROM x WHERE a = ? AND b = ?',
            SqlFingerprint::normalize('SELECT * FROM x WHERE a = -3 AND b = 1.50')
        );
    }

    public function test_digits_inside_an_identifier_are_left_alone(): void
    {
        $this->assertSame(
            'SELECT b100, authy2 FROM x',
            SqlFingerprint::normalize('SELECT b100, authy2 FROM x')
        );
    }

    public function test_existing_placeholders_are_left_alone(): void
    {
        $this->assertSame(
            'SELECT * FROM x WHERE id = ? AND name = :name',
            SqlFingerprint::normalize('SELECT * FROM x WHERE id = ? AND name = :name')
        );
    }

    public function test_truncates_to_1024_chars(): void
    {
        $sql = 'SELECT * FROM x WHERE ' . \str_repeat('a', 2000);

        $normalized = SqlFingerprint::normalize($sql);

        $this->assertSame(1024, \strlen($normalized));
    }

    public function test_hash_is_sha1_of_the_normalized_sql(): void
    {
        $sql = "SELECT * FROM authy WHERE email = 'a@b.c'";

        $this->assertSame(
            \sha1(SqlFingerprint::normalize($sql)),
            SqlFingerprint::hash($sql)
        );
        $this->assertSame(40, \strlen(SqlFingerprint::hash($sql)));
    }

    public function test_hash_is_stable_across_different_literal_values(): void
    {
        $this->assertSame(
            SqlFingerprint::hash('SELECT * FROM x WHERE id = 1'),
            SqlFingerprint::hash('SELECT * FROM x WHERE id = 999999')
        );
    }

    // ── Final review I3: unquoted hex literals and scientific notation.

    public function test_hex_literals_stripped(): void
    {
        $this->assertSame(
            'SELECT * FROM t WHERE h = ? OR h = ? AND b100 = ?',
            SqlFingerprint::normalize('SELECT * FROM t WHERE h = 0x1A2B OR h = 0XDEADbeef AND b100 = 0x0')
        );
    }

    public function test_scientific_notation_stripped(): void
    {
        $this->assertSame(
            'SELECT * FROM t WHERE x > ? AND y < ? AND z = ?',
            SqlFingerprint::normalize('SELECT * FROM t WHERE x > 1.5e10 AND y < -2E-3 AND z = 3e+2')
        );
    }

    public function test_hex_and_exponent_values_share_a_hash(): void
    {
        $this->assertSame(
            SqlFingerprint::hash('SELECT 1 FROM t WHERE h = 0xAA AND x = 1e5'),
            SqlFingerprint::hash('SELECT 2 FROM t WHERE h = 0xBBCC AND x = 2.5E-7')
        );
    }
}
