<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Api;

use ApiGoat\Api\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Review 3 (2026-09-23): the owner/group/tenant filter guard compared the
 * camelized column case-sensitively, but PHP method names are not — so
 * "idcreation" / "IDCREATION" reached filterByIdCreation(), merged into the
 * ACL criterion and an `or` removed the scope. Every spelling that resolves
 * to an ACL filter method must be recognised.
 */
final class AclColumnFilterGuardTest extends TestCase
{
    /** @dataProvider aclSpellings */
    public function testEverySpellingOfAnAclColumnIsGuarded(string $name): void
    {
        $this->assertTrue(QueryBuilder::isAclColumnName($name), $name);
    }

    public static function aclSpellings(): array
    {
        return array_map(fn ($n) => [$n], [
            'id_creation', 'idcreation', 'IDCREATION', 'IdCreation', 'idCreation',
            'id-creation', 'id creation', ' id_creation ',
            'id_group_creation', 'IDGROUPCREATION', 'id-group-creation',
            'id_tenant', 'ID_TENANT', 'idtenant', 'IdTenant',
        ]);
    }

    public function testOtherColumnsAreNotGuarded(): void
    {
        foreach (['id_modification', 'status', 'id_contact', 'creation', '', 'id'] as $name) {
            $this->assertFalse(QueryBuilder::isAclColumnName($name), $name);
        }
        $this->assertFalse(QueryBuilder::isAclColumnName(null));
        $this->assertFalse(QueryBuilder::isAclColumnName(['id_creation']));
    }
}
