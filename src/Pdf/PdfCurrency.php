<?php

namespace ApiGoat\Pdf;

/**
 * Resolves the document currency for a with_pdf record, so money fields
 * (items, totals, {{money}} columns, {{currency}} token) format in the
 * document's own currency instead of a hardcoded default.
 *
 * The manifest 'currency' entry is a getter-PATH off the record (like
 * folder/filename placeholders), resolved segment by segment:
 *   - "currency"        → $record->getCurrency()               (scalar column,
 *                          e.g. apicrm's currency VARCHAR 'CAD')
 *   - "Currency.Name"   → $record->getCurrency()->getName()    (FK to a currency
 *                          table, e.g. apigoatacc's default_currency → currency)
 * Empty/unresolvable → the fallback (CAD), preserving the prior behavior.
 */
final class PdfCurrency
{
    public const FALLBACK = 'CAD';

    public static function resolve(object $record, array $entry): string
    {
        $path = trim((string) ($entry['currency'] ?? ''));
        if ($path === '') {
            return self::FALLBACK;
        }

        $obj = $record;
        foreach (explode('.', $path) as $hop) {
            if (!is_object($obj)) {
                return self::FALLBACK;
            }
            $getter = 'get' . $hop;
            if (!method_exists($obj, $getter)) {
                return self::FALLBACK;
            }
            $obj = $obj->$getter();
        }

        $code = is_scalar($obj) ? trim((string) $obj) : '';
        return $code !== '' ? $code : self::FALLBACK;
    }
}
