<?php

namespace ApiGoat\Ops\Server;

/**
 * Turns a project's `server_source` config value into a concrete Source.
 *
 * R4 (controller ruling, Task 6): only 'snapshot' has an implementation.
 * IspconfigSource was ruled out entirely — prod runs the app as a jailed
 * ISPConfig web user with no MySQL/root access to read
 * dbispconfig.monitor_data, so it was never buildable — so 'ispconfig' maps
 * to null exactly like 'none' or any other unrecognized string (a config
 * typo must degrade to "no source", never throw).
 */
final class Factory
{
    /** Default snapshot path when Config::get('snapshot_path') is ''. */
    private const DEFAULT_SNAPSHOT_PATH = 'tmp/ops-snapshot.json';

    public static function make(string $source, ?string $path = null): ?Source
    {
        if ($source !== 'snapshot') {
            return null;
        }

        return new SnapshotFileSource($path !== null && $path !== '' ? $path : self::defaultPath());
    }

    /** _BASE_DIR . 'tmp/ops-snapshot.json' when _BASE_DIR is defined (always true at runtime). */
    private static function defaultPath(): string
    {
        return \defined('_BASE_DIR') ? (_BASE_DIR . self::DEFAULT_SNAPSHOT_PATH) : self::DEFAULT_SNAPSHOT_PATH;
    }
}
