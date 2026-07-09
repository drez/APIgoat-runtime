<?php

namespace ApiGoat\Pdf;

/**
 * Reader for the build-emitted with_pdf manifest (config/Built/pdf.php),
 * written by the emitter's Parameters/with_pdf.php buildBaseData hook.
 *
 * Entry shape (all names resolved at BUILD time so nothing here reflects):
 *   '<table>' => [
 *     'entity'   => 'Billing',            // PhpName → \App\Billing(Query)
 *     'label'    => 'Bill',               // table description → {{title}}
 *     'type'     => 'invoice',            // quote|invoice|generic|custom
 *     'class'    => null,                 // custom renderer FQCN (type custom)
 *     'lines'    => ['entity','fk_php','columns'=>[['php','snake','label','kind'],…]] | null,
 *     'details'  => ['entity','fk_php','name_php','value_php'] | null,
 *     'totals'   => [['php','label','kind'],…],
 *     'templates'=> ['header'=>?,'body'=>?,'footer'=>?],   // name or prefix% overrides
 *     'storage'  => ['local','drive'…],
 *     'folder'   => null|'Billing/{Client.Name}',
 *     'filename' => null|'Bill-{IdBilling}-{Client.Name}',
 *     'pk_php'   => 'IdBilling',
 *     'columns'  => [['php','snake','label','kind'],…],    // parent columns for {{col}} + generic body
 *     'files'    => ['entity','table','fk_php','name_php','file_php','class_dir'] | null,
 *     'lang_source' => null|'Lang',
 *     'drive_browser' => false,
 *   ]
 * kind: text | num | money | date
 */
final class PdfManifest
{
    /** @var array<string,array>|null */
    private static ?array $cache = null;

    /** @return array<string,array> table => entry ([] when no manifest). */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (defined('_BASE_DIR') && is_file(_BASE_DIR . 'config/Built/pdf.php')) {
                $m = require _BASE_DIR . 'config/Built/pdf.php';
                if (is_array($m)) {
                    self::$cache = $m;
                }
            }
        }
        return self::$cache;
    }

    public static function entry(string $table): ?array
    {
        return self::all()[strtolower($table)] ?? null;
    }

    /** Entry lookup that also accepts the entity PhpName ('Billing'). */
    public static function entryFor(string $tableOrEntity): ?array
    {
        $e = self::entry($tableOrEntity);
        if ($e !== null) {
            return $e;
        }
        foreach (self::all() as $entry) {
            if (strcasecmp((string) ($entry['entity'] ?? ''), $tableOrEntity) === 0) {
                return $entry;
            }
        }
        return null;
    }

    public static function available(): bool
    {
        return self::all() !== [];
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
