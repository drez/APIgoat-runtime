<?php

namespace ApiGoat\Sync;

use ApiGoat\Sync\QuickBooks\QboProvider;

/** Production wiring: closures over Propel + the fully-assembled engine. */
final class SyncRuntime
{
    public static function engine(): ?SyncEngine
    {
        $map = SyncMap::load();
        if (!$map || !class_exists('\App\AcctLink')) {
            return null;
        }
        $provider = QboProvider::fromProject();
        if (!$provider) {
            return null;
        }
        return new SyncEngine($provider, new PropelLinkStore(), self::loadRecord(), self::loadChildren(), $map);
    }

    /** fn(string $table, int $pk): ?array — column => value (Propel fieldnames). */
    public static function loadRecord(): \Closure
    {
        return static function (string $table, int $pk): ?array {
            $queryClass = '\\App\\' . self::phpName($table) . 'Query';
            if (!class_exists($queryClass)) {
                return null;
            }
            $obj = $queryClass::create()->findPk($pk);
            return $obj ? $obj->toArray(\BasePeer::TYPE_FIELDNAME) : null;
        };
    }

    /** fn(string $childTable, string $fkCol, int $parentPk): array<array> */
    public static function loadChildren(): \Closure
    {
        return static function (string $table, string $fkCol, int $pk): array {
            $queryClass = '\\App\\' . self::phpName($table) . 'Query';
            if (!class_exists($queryClass)) {
                return [];
            }
            $out = [];
            foreach ($queryClass::create()->filterBy(self::phpName($fkCol), $pk)->find() as $o) {
                $out[] = $o->toArray(\BasePeer::TYPE_FIELDNAME);
            }
            return $out;
        };
    }

    /** fn(string $table, array $values): int — insert one row, return its pk. */
    public static function insertRow(): \Closure
    {
        return static function (string $table, array $values): int {
            $cls = '\\App\\' . self::phpName($table);
            $obj = new $cls();
            $obj->fromArray($values, \BasePeer::TYPE_FIELDNAME);
            $obj->save();
            return (int) $obj->getPrimaryKey();
        };
    }

    /** fn(string $table, int $pk, array $values): void — partial column update. */
    public static function updateRow(): \Closure
    {
        return static function (string $table, int $pk, array $values): void {
            $queryClass = '\\App\\' . self::phpName($table) . 'Query';
            $obj = $queryClass::create()->findPk($pk);
            if (!$obj) {
                return;
            }
            $obj->fromArray($values, \BasePeer::TYPE_FIELDNAME);
            $obj->save();
        };
    }

    /** acct_sync_job → AcctSyncJob, id_invoice → IdInvoice. */
    public static function phpName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
