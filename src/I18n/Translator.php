<?php

declare(strict_types=1);

namespace ApiGoat\I18n;

/**
 * Structural-label dictionary lookup for generated documents and emails.
 *
 * Dictionaries are flat associative arrays (dotted keys) in <dir>/<locale>.php.
 * A framework BASE dictionary ships alongside this class; projects register
 * additional directories via registerDictionaryPath(), each merged OVER the base
 * (later registrations win). Lookups fall back locale -> DEFAULT -> key itself.
 */
final class Translator
{
    /** Default locale: ultimate fallback and column default. */
    public const DEFAULT = 'fr_CA';

    /** @var string[] Registered project dictionary dirs (base is implicit, first). */
    private static array $paths = [];

    /** @var array<string, array<string, string>> Per-locale merged require-cache. */
    private static array $cache = [];

    /** Register a project dictionary directory (merged over the framework base). */
    public static function registerDictionaryPath(string $dir): void
    {
        $dir = rtrim($dir, '/');
        if ($dir !== '' && !in_array($dir, self::$paths, true)) {
            self::$paths[] = $dir;
            self::$cache = [];
        }
    }

    /** Translate $key into $locale (locale -> DEFAULT -> key). */
    public static function t(string $key, string $locale): string
    {
        $dict = self::load($locale);
        if (array_key_exists($key, $dict)) {
            return (string) $dict[$key];
        }
        if ($locale !== self::DEFAULT) {
            $fallback = self::load(self::DEFAULT);
            if (array_key_exists($key, $fallback)) {
                return (string) $fallback[$key];
            }
        }
        return $key;
    }

    /** doc.month.01 .. doc.month.12 convenience. */
    public static function month(int $n, string $locale): string
    {
        return self::t('doc.month.' . sprintf('%02d', $n), $locale);
    }

    /** Base dir shipped with the runtime, then any registered project dirs. */
    private static function dirs(): array
    {
        return array_merge([__DIR__ . '/lang'], self::$paths);
    }

    private static function load(string $locale): array
    {
        if (!isset(self::$cache[$locale])) {
            $merged = [];
            foreach (self::dirs() as $dir) {
                $file = $dir . '/' . $locale . '.php';
                if (is_file($file)) {
                    $merged = array_merge($merged, (array) require $file);
                }
            }
            self::$cache[$locale] = $merged;
        }
        return self::$cache[$locale];
    }

    /** Test seam: forget registered paths + cache. */
    public static function reset(): void
    {
        self::$paths = [];
        self::$cache = [];
    }
}
