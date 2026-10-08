<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Db/UniqueViolation.php';

use ApiGoat\Db\UniqueViolation;
use PHPUnit\Framework\TestCase;

final class UniqueViolationTest extends TestCase
{
    private const KEYS = ['client_U_1' => ['id_tenant', 'name'], 'tenant_only_U_1' => ['id_tenant']];

    private function pdo(string $msg, int $code = 1062): \PDOException
    {
        $e = new \PDOException($msg);
        $e->errorInfo = ['23000', $code, $msg];
        return $e;
    }

    public function test_1062_maps_to_user_columns_without_tenant(): void
    {
        $e = $this->pdo("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-ll-teq' for key 'client_U_1'");
        $this->assertSame(['name' => 'Already exists'], UniqueViolation::errors($e, self::KEYS));
    }

    public function test_qualified_key_name_and_wrapped_exception(): void
    {
        $inner = $this->pdo("Duplicate entry '1-x' for key 'client.client_U_1'");
        $outer = new \RuntimeException('Unable to execute INSERT', 0, $inner);
        $this->assertSame(['name' => 'Already exists'], UniqueViolation::errors($outer, self::KEYS));
    }

    public function test_value_containing_for_key_text_does_not_misattribute(): void
    {
        $e = $this->pdo("Duplicate entry '1-a' for key 'authy_U_1' b' for key 'client_U_1'");
        $this->assertSame(['name' => 'Already exists'], UniqueViolation::errors($e, self::KEYS + ['authy_U_1' => ['username']]));
    }

    public function test_only_system_columns_keys_on_first_column(): void
    {
        $e = $this->pdo("Duplicate entry '1' for key 'tenant_only_U_1'");
        $this->assertSame(['id_tenant' => 'Already exists'], UniqueViolation::errors($e, self::KEYS));
    }

    public function test_other_errors_are_null(): void
    {
        $this->assertNull(UniqueViolation::errors($this->pdo('Deadlock found', 1213), self::KEYS));
        $this->assertNull(UniqueViolation::errors(new \RuntimeException('boom'), self::KEYS));
        $this->assertNull(UniqueViolation::errors($this->pdo("Duplicate entry '1' for key 'PRIMARY'"), self::KEYS));
    }

    public function test_ext_validation_shape(): void
    {
        $this->assertSame(['Already exists' => ['fields' => ['a', 'b']]], UniqueViolation::asExtValidation(['a' => 'Already exists', 'b' => 'Already exists']));
    }
}
