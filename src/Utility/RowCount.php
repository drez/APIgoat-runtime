<?php

namespace ApiGoat\Utility;

/**
 * Per-request memo of "\App\{Model}Query::create()->count()" results.
 *
 * Promoted from Menu::countFor() (T8 of the edit-drawer fidelity plan)
 * so the same memoised count can be reused by the drawer's child-tab
 * strip emitter without coupling to a Menu instance.
 *
 * Failures (missing class, no DB column, exception) yield null and the
 * caller omits the chip. Never fatal, never unbounded slow queries.
 */
class RowCount
{
    /** @var array<string, int|null> */
    private static $cache = [];

    /**
     * @param  string $Model  canonical model name (already camelized by caller)
     * @return int|null       null => omit the chip
     */
    public static function forModel($Model)
    {
        if (!is_string($Model) || $Model === '') {
            return null;
        }
        if (array_key_exists($Model, self::$cache)) {
            return self::$cache[$Model];
        }

        $count = null;
        $queryClass = '\\App\\' . $Model . 'Query';
        if (class_exists($queryClass)) {
            try {
                $count = (int) $queryClass::create()->count();
            } catch (\Throwable $e) {
                $count = null;
            }
        }

        self::$cache[$Model] = $count;
        return $count;
    }
}
