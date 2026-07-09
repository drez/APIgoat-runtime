<?php

namespace ApiGoat\Pdf;

/**
 * Content-staleness for with_pdf saved copies. Generalizes apicrm's
 * DocumentTouch: the newest date_modification across the parent row, the
 * declared lines child, the declared details child, and their _i18n
 * companions when those tables exist. Compared against pdf_saved_at.
 */
final class PdfStaleness
{
    /**
     * Newest content edit ('Y-m-d H:i:s') for record $id of manifest $entry,
     * or null when there is no timestamp signal.
     */
    public static function latest(array $entry, int $id): ?string
    {
        $parent = self::ident(self::tableOf($entry));
        $pk     = 'id_' . $parent;

        $selects = ["SELECT date_modification AS m FROM `{$parent}` WHERE `{$pk}` = ?"];
        $params  = [$id];

        foreach (self::childTables($entry) as $child) {
            $child = self::ident($child);
            if (!self::tableExists($child) || !self::columnExists($child, $pk)) {
                continue;
            }
            $selects[] = "SELECT date_modification FROM `{$child}` WHERE `{$pk}` = ?";
            $params[]  = $id;

            $i18n    = $child . '_i18n';
            $childPk = 'id_' . $child;
            if (self::tableExists($i18n) && self::columnExists($i18n, $childPk)) {
                $selects[] = "SELECT i.date_modification FROM `{$i18n}` i"
                    . " JOIN `{$child}` c ON c.`{$childPk}` = i.`{$childPk}` WHERE c.`{$pk}` = ?";
                $params[]  = $id;
            }
        }

        $sql = 'SELECT MAX(m) FROM (' . implode(' UNION ALL ', $selects) . ') t';
        $st  = \Propel::getConnection()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();

        return ($v !== false && $v !== null && $v !== '') ? (string) $v : null;
    }

    /**
     * True only when a saved copy exists AND no content was modified after it
     * was generated. Pure core (ported from apicrm SavedPdfGenerator) — the
     * url/language and timestamp rules stay unit-testable without a DB.
     */
    public static function isCurrentGiven(
        string $storedUrl,
        ?string $touchedAt,
        ?string $savedAt
    ): bool {
        if ($storedUrl === '') {
            return false;
        }
        if ($touchedAt === null) {
            return true; // no timestamp signal — a stored copy counts as current
        }
        if ($savedAt === null) {
            return false; // unknown generation time can't be proven current
        }
        // 'Y-m-d H:i:s' compares correctly as strings; equality = the
        // bookkeeping save from the generator itself, which stays current.
        return $touchedAt <= $savedAt;
    }

    /** Record-level convenience over isCurrentGiven(). */
    public static function savedCopyIsCurrent(object $record, array $entry): bool
    {
        $url = (string) $record->getPdfUrl();
        if ($url === '') {
            return false;
        }
        $pkGetter = 'get' . $entry['pk_php'];
        $savedAt  = $record->getPdfSavedAt('Y-m-d H:i:s');
        return self::isCurrentGiven(
            $url,
            self::latest($entry, (int) $record->$pkGetter()),
            ($savedAt !== null && $savedAt !== '') ? (string) $savedAt : null
        );
    }

    /** @return string[] declared child table names (lines, details). */
    private static function childTables(array $entry): array
    {
        $out = [];
        foreach (['lines', 'details'] as $k) {
            $t = $entry[$k]['table'] ?? null;
            if (is_string($t) && $t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** Parent table name: the manifest key is passed via 'table'. */
    private static function tableOf(array $entry): string
    {
        return (string) ($entry['table'] ?? '');
    }

    /** Manifest values are build-emitted (trusted) — still refuse odd identifiers. */
    private static function ident(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid table identifier '{$name}'");
        }
        return $name;
    }

    private static array $tableCache = [];

    private static function tableExists(string $table): bool
    {
        if (!array_key_exists($table, self::$tableCache)) {
            $st = \Propel::getConnection()->prepare('SHOW TABLES LIKE ?');
            $st->execute([$table]);
            self::$tableCache[$table] = $st->fetchColumn() !== false;
        }
        return self::$tableCache[$table];
    }

    private static array $colCache = [];

    private static function columnExists(string $table, string $col): bool
    {
        $key = $table . '.' . $col;
        if (!array_key_exists($key, self::$colCache)) {
            $st = \Propel::getConnection()->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $st->execute([$col]);
            self::$colCache[$key] = $st->fetchColumn() !== false;
        }
        return self::$colCache[$key];
    }
}
