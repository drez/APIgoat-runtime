<?php

namespace ApiGoat\Pdf;

/**
 * Filename rules for with_pdf saved copies. Ported from apicrm's
 * App\Domains\Pdf\SavedPdfNaming, extended with the backup naming used by the
 * behavior's replace-in-place model: the CURRENT copy always holds the bare
 * canonical name; "Backup this version" renames it to "<base>_bak<N>.pdf".
 */
final class PdfNaming
{
    /** Filesystem/Drive-safe "<business>-<number>.pdf" (segments slugged). */
    public static function filename(?string $business, ?string $contact, ?string $number): string
    {
        $biz = (string) $business;
        if (self::slug($biz) === '') {
            $biz = (string) $contact;
        }

        $base = trim(self::slug($biz) . '-' . self::slug((string) $number), '-');
        if ($base === '') {
            $base = 'document';
        }
        return $base . '.pdf';
    }

    /** Slug an arbitrary template-resolved name and ensure the .pdf extension. */
    public static function fromTemplate(string $resolved): string
    {
        $base = self::slug(preg_replace('/\.pdf$/i', '', $resolved));
        return ($base !== '' ? $base : 'document') . '.pdf';
    }

    /**
     * Next backup name for the canonical file: "<base>_bak<N>.pdf" where N is
     * one past the highest _bak number in $existingNames. Names that don't
     * match the backup shape for THIS base are ignored.
     */
    public static function nextBackupName(string $canonicalName, array $existingNames): string
    {
        [$base, $ext] = self::split($canonicalName);
        $pattern = '/^' . preg_quote($base, '/') . '_bak(\d+)' . preg_quote($ext, '/') . '$/';
        $max = 0;
        foreach ($existingNames as $name) {
            if (preg_match($pattern, (string) $name, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $base . '_bak' . ($max + 1) . $ext;
    }

    /** True when $name is a backup copy of $canonicalName. */
    public static function isBackupOf(string $canonicalName, string $name): bool
    {
        [$base, $ext] = self::split($canonicalName);
        return (bool) preg_match(
            '/^' . preg_quote($base, '/') . '_bak\d+' . preg_quote($ext, '/') . '$/',
            $name
        );
    }

    /** @return array{0:string,1:string} [base, ext] */
    private static function split(string $name): array
    {
        if (preg_match('/^(.*?)(\.[^.]+)$/', $name, $m)) {
            return [$m[1], $m[2]];
        }
        return [$name, ''];
    }

    public static function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($s));
        $s = preg_replace('/-+/', '-', (string) $s);
        return trim((string) $s, '-_.');
    }
}
