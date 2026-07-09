<?php

namespace ApiGoat\Pdf;

/**
 * Filename rules for the single saved with_pdf copy (ported from apicrm's
 * App\Domains\Pdf\SavedPdfNaming). One canonical name per document; regenerate
 * overwrites it in place.
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

    public static function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($s));
        $s = preg_replace('/-+/', '-', (string) $s);
        return trim((string) $s, '-_.');
    }
}
