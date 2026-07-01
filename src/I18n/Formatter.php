<?php

declare(strict_types=1);

namespace ApiGoat\I18n;

/**
 * Locale-aware numeric/date formatting shared by generated documents.
 *   money: fr_CA '88 440,00 $'  |  en_US '$88,440.00' (non-CAD keeps ' CODE').
 *   dateLong: '2026-05-13' -> '13 mai 2026' / '13 May 2026' (months from Translator).
 *   rate: trailing-zero-trimmed; fr_CA comma decimal, en_US period.
 */
final class Formatter
{
    public static function money(?string $amount, string $currency = 'CAD', string $locale = 'fr_CA'): string
    {
        if ($locale === 'en_US') {
            $n = number_format((float) ($amount ?: '0'), 2, '.', ',');
            return $currency === 'CAD' ? '$' . $n : $n . ' ' . $currency;
        }
        $n = number_format((float) ($amount ?: '0'), 2, ',', ' ');
        return $currency === 'CAD' ? $n . ' $' : $n . ' ' . $currency;
    }

    /** '2026-05-13' -> '13 mai 2026' / '13 May 2026'; non-ISO input returned as-is. */
    public static function dateLong(?string $iso, string $locale = 'fr_CA'): string
    {
        if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
            return (string) $iso;
        }
        $month = Translator::t('doc.month.' . sprintf('%02d', (int) $m[2]), $locale);
        return (int) $m[3] . ' ' . $month . ' ' . $m[1];
    }

    /** Rate with trailing zeros trimmed ('5.00' -> '5'); fr_CA comma decimal. */
    public static function rate(string $rate, string $locale = 'fr_CA'): string
    {
        if (str_contains($rate, '.')) {
            $rate = rtrim(rtrim($rate, '0'), '.');
        }
        return $locale === 'en_US' ? $rate : str_replace('.', ',', $rate);
    }
}
