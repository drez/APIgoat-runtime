<?php

declare(strict_types=1);

namespace ApiGoat\I18n;

/**
 * Generic locale resolution + second-language filename tagging. Domain-specific
 * resolvers (e.g. "for this quote") assemble the candidate list and delegate to
 * resolve(); this class knows nothing about any model.
 */
final class LocaleResolver
{
    public const SUPPORTED = ['fr_CA', 'en_US'];
    public const DEFAULT = 'fr_CA';

    /** First supported candidate wins (in order); else $default. */
    public static function resolve(array $candidates, string $default = self::DEFAULT): string
    {
        foreach ($candidates as $c) {
            $s = self::sanitize(is_string($c) || $c === null ? $c : (string) $c);
            if ($s !== null) {
                return $s;
            }
        }
        return $default;
    }

    /** Return $loc only if supported, else null. */
    public static function sanitize(?string $loc): ?string
    {
        return in_array($loc, self::SUPPORTED, true) ? $loc : null;
    }

    public static function isValid(string $loc): bool
    {
        return in_array($loc, self::SUPPORTED, true);
    }

    /** Second-language-only filename tag: 'EN' for en_US, '' for the default. */
    public static function tag(string $loc): string
    {
        return $loc === 'en_US' ? 'EN' : '';
    }
}
