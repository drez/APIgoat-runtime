<?php

namespace ApiGoat\Pdf;

use ApiGoat\Storage\Drive\GoogleDriveStorage;

/**
 * Storage orchestrator for with_pdf documents. There is exactly ONE saved PDF
 * per document (no versioning): generate() renders and OVERWRITES the current
 * copy in every configured store, updating pdf_url/pdf_saved_at/pdf_lang.
 *
 * Local store = a row in the table's file child (is_file_upload_table
 * conventions: file at public/file/<ChildClass>/md5(childPk).pdf, display
 * name in the child's name column). Drive store = the canonical filename in
 * the resolved folder via the shared GoogleDriveStorage.
 */
final class PdfGenerator
{
    /**
     * @return array{url:string, bytes:string, name:string, lang:string}
     */
    public static function generate(object $record, array $entry, ?int $templateId = null, string $workspaceEmail = ''): array
    {
        $doc   = PresetRenderer::render($record, $entry, $templateId);
        $bytes = (new HtmlToPdf())->render($doc['html']);
        $name  = self::canonicalName($record, $entry);
        $url   = '';

        if (self::hasLocal($entry)) {
            $url = self::writeLocalCurrent($record, $entry, $name, $bytes);
        }

        if (self::hasDrive($entry)) {
            try {
                $drive = self::driveFor($workspaceEmail);
                $scope = self::folderScope($record, $entry);
                foreach (self::driveMatches($drive, $scope, $name) as $f) {
                    if (($f['name'] ?? '') === $name && !empty($f['id'])) {
                        $drive->delete((string) $f['id']);
                    }
                }
                $up = $drive->upload($scope, $name, $bytes, 'application/pdf');
                if (!empty($up['webViewLink'])) {
                    $url = (string) $up['webViewLink']; // Drive link wins as pdf_url
                }
            } catch (\Throwable $e) {
                // Drive is best-effort when misconfigured: local copy already
                // succeeded (or drive-only callers get the exception below).
                if (!self::hasLocal($entry)) {
                    throw $e;
                }
                error_log('[with_pdf] drive store failed: ' . $e->getMessage());
            }
        }

        self::storeSaved($record, $entry, $url, $doc['lang']);

        return ['url' => $url, 'bytes' => $bytes, 'name' => $name, 'lang' => $doc['lang']];
    }

    /**
     * Whether a CURRENT copy exists (local row with its file on disk, or a
     * Drive-backed pdf_url). Lets the download path distinguish "stream what
     * exists" (read right) from "must generate first" (write right).
     */
    public static function hasCurrent(object $record, array $entry): bool
    {
        $name = self::canonicalName($record, $entry);
        if (self::hasLocal($entry)) {
            $row = self::localCurrentRow($record, $entry, $name);
            if ($row !== null) {
                $fileGetter = 'get' . $entry['files']['file_php'];
                if (is_file(self::baseDir() . (string) $row->$fileGetter())) {
                    return true;
                }
            }
            return false;
        }
        return (string) $record->getPdfUrl() !== '';
    }

    /**
     * Stream-ready bytes of the CURRENT copy; generates one when absent.
     * @return array{bytes:string, name:string, generated:bool}
     */
    public static function currentBytes(object $record, array $entry, string $workspaceEmail = ''): array
    {
        $name = self::canonicalName($record, $entry);
        if (self::hasLocal($entry)) {
            $row = self::localCurrentRow($record, $entry, $name);
            if ($row !== null) {
                $fileGetter = 'get' . $entry['files']['file_php'];
                $path = self::baseDir() . (string) $row->$fileGetter();
                if (is_file($path)) {
                    return ['bytes' => (string) file_get_contents($path), 'name' => $name, 'generated' => false];
                }
            }
        }
        // Drive-only (or missing local file): render fresh — always current.
        $res = self::generate($record, $entry, null, $workspaceEmail);
        return ['bytes' => $res['bytes'], 'name' => $res['name'], 'generated' => true];
    }

    /** Drive folder webViewLink for the "Open gDrive" menu item (null when unavailable). */
    public static function driveFolderLink(object $record, array $entry, string $workspaceEmail = ''): ?string
    {
        if (!self::hasDrive($entry)) {
            return null;
        }
        try {
            return self::driveFor($workspaceEmail)->folderLink(self::folderScope($record, $entry));
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── naming / paths ─────────────────────────────────────────────────────

    /** Canonical current-copy filename for the record. */
    public static function canonicalName(object $record, array $entry): string
    {
        $tplName = (string) ($entry['filename'] ?? '');
        if ($tplName !== '') {
            return PdfNaming::fromTemplate(self::resolvePlaceholders($tplName, $record));
        }
        $pkGetter = 'get' . $entry['pk_php'];
        return PdfNaming::filename((string) ($entry['entity'] ?? 'document'), null, (string) $record->$pkGetter());
    }

    /** Drive folder path (scope string) for the record. */
    public static function folderScope(object $record, array $entry): string
    {
        $tpl = (string) ($entry['folder'] ?? '');
        if ($tpl === '') {
            return (string) $entry['entity'];
        }
        $resolved = self::resolvePlaceholders($tpl, $record);
        $parts = array_filter(array_map(
            fn($seg) => PdfNaming::slug($seg),
            explode('/', $resolved)
        ), fn($s) => $s !== '');
        return implode('/', $parts);
    }

    /** '{PhpName}' → getter value; '{Rel.PhpName}' → related getter chain. */
    private static function resolvePlaceholders(string $tpl, object $record): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', function ($m) use ($record) {
            $obj = $record;
            foreach (explode('.', $m[1]) as $hop) {
                if (!is_object($obj)) {
                    return '';
                }
                $getter = 'get' . $hop;
                if (!method_exists($obj, $getter)) {
                    return '';
                }
                $obj = $obj->$getter();
            }
            if ($obj instanceof \DateTime) {
                return $obj->format('Y-m-d');
            }
            return is_scalar($obj) ? (string) $obj : '';
        }, $tpl);
    }

    // ── local store ────────────────────────────────────────────────────────

    private static function writeLocalCurrent(object $record, array $entry, string $name, string $bytes): string
    {
        $files = $entry['files'];
        $row = self::localCurrentRow($record, $entry, $name);
        if ($row === null) {
            $class = '\\App\\' . $files['entity'];
            $row = new $class();
            $fkSetter = 'set' . $files['fk_php'];
            $pkGetter = 'get' . $entry['pk_php'];
            $row->$fkSetter($record->$pkGetter());
            $nameSetter = 'set' . $files['name_php'];
            $row->$nameSetter($name);
            $row->save(); // pk needed for the md5 filename
        }

        $pkVal   = (string) $row->getPrimaryKey();
        $relPath = 'public/file/' . $files['class_dir'] . '/' . md5($pkVal) . '.pdf';
        $absDir  = self::baseDir() . 'public/file/' . $files['class_dir'];
        self::ensureHardenedDir($absDir);
        file_put_contents(self::baseDir() . $relPath, $bytes);

        $fileSetter = 'set' . $files['file_php'];
        $row->$fileSetter($relPath);
        $nameSetter = 'set' . $files['name_php'];
        $row->$nameSetter($name);
        $row->save();

        return $relPath;
    }

    /** The child row holding the CURRENT copy (name == canonical), or null. */
    private static function localCurrentRow(object $record, array $entry, string $canonical): ?object
    {
        foreach (self::localRows($record, $entry) as $row) {
            $nameGetter = 'get' . $entry['files']['name_php'];
            if ((string) $row->$nameGetter() === $canonical) {
                return $row;
            }
        }
        return null;
    }

    /** @return object[] all file-child rows of the record. */
    private static function localRows(object $record, array $entry): array
    {
        $files = $entry['files'] ?? null;
        if (!$files) {
            return [];
        }
        $queryClass = '\\App\\' . $files['entity'] . 'Query';
        if (!class_exists($queryClass)) {
            return [];
        }
        $filter   = 'filterBy' . $files['fk_php'];
        $pkGetter = 'get' . $entry['pk_php'];
        return iterator_to_array($queryClass::create()->$filter($record->$pkGetter())->find(), false);
    }

    /**
     * Mirror is_file_upload_table's directory hardening for direct writes:
     * no script execution inside upload dirs + a directory-listing guard.
     */
    private static function ensureHardenedDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            file_put_contents(
                $ht,
                "# gc:with_pdf — uploaded content must never execute\n"
                . "SetHandler none\nSetHandler default-handler\n"
                . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py\n"
                . "RemoveType .php .phtml\nphp_flag engine off\n"
                . "Options -ExecCGI -Indexes\n"
            );
        }
        $ix = $dir . '/index.php';
        if (!is_file($ix)) {
            file_put_contents($ix, "<?php http_response_code(403);\n");
        }
    }

    // ── drive store ────────────────────────────────────────────────────────

    private static function hasLocal(array $entry): bool
    {
        return in_array('local', (array) ($entry['storage'] ?? []), true) && !empty($entry['files']);
    }

    private static function hasDrive(array $entry): bool
    {
        return in_array('drive', (array) ($entry['storage'] ?? []), true);
    }

    private static function driveFor(string $workspaceEmail): GoogleDriveStorage
    {
        if ($workspaceEmail === '') {
            throw new \RuntimeException('No Google Workspace email on the current user — Drive storage unavailable.');
        }
        return GoogleDriveStorage::forUser($workspaceEmail);
    }

    /** @return array[] folder items whose name starts with $prefix ('' = all). */
    private static function driveMatches(GoogleDriveStorage $drive, string $scope, string $prefix): array
    {
        $filters = $prefix !== '' ? ['name_starts_with' => $prefix] : [];
        return (array) ($drive->list($scope, $filters)['items'] ?? []);
    }


    // ── parent bookkeeping ─────────────────────────────────────────────────

    private static function storeSaved(object $record, array $entry, string $url, string $lang): void
    {
        $record->setPdfUrl($url !== '' ? $url : null);
        $record->setPdfSavedAt(date('Y-m-d H:i:s'));
        $record->setPdfLang($lang);
        $record->save();
        // Align pdf_saved_at with the tablestamp this save just wrote so the
        // bookkeeping save never reads as "content changed after PDF".
        $table = (string) $entry['table'];
        if (preg_match('/^[a-z0-9_]+$/', $table)) {
            $pkGetter = 'get' . $entry['pk_php'];
            \Propel::getConnection()
                ->prepare("UPDATE `{$table}` SET pdf_saved_at = date_modification WHERE `id_{$table}` = ?")
                ->execute([(int) $record->$pkGetter()]);
        }
    }

    private static function baseDir(): string
    {
        return defined('_BASE_DIR') ? _BASE_DIR : '';
    }
}
