<?php

namespace ApiGoat\Ops;

/**
 * Reader for the build-emitted ops-monitor manifest (config/Built/ops_monitor.php).
 * Mirror of ApiGoat\Ai\AiManifest / config/Built/ai.php: the behavior
 * (Parameters/with_ops_monitor.php) writes the resolved config once at build
 * time, this class `require`s it on demand and caches it, and every runtime
 * feature gates on enabled() rather than checking the file itself.
 *
 * R8 (controller ruling, Task 2): the manifest is
 * `_BASE_DIR . 'config/Built/ops_monitor.php'`, a plain
 * `<?php return [...];` with these keys:
 *   slow_ms, slow_query_ms, raw_days, rollup_days, server_source,
 *   report_to, snapshot_path.
 * enabled() is true only when _BASE_DIR is defined AND that file exists —
 * a project that never declared `with_ops_monitor` has no file at all, so
 * every ops_* runtime path (RequestRecorder::defer, and later the query and
 * security recorders) stays inert.
 */
final class Config
{
    /** Built-in defaults — used both when the manifest omits a key and when
     *  there is no manifest at all (enabled() will then be false, but get()
     *  still returns a sane value for a caller that reads it directly). */
    private const DEFAULTS = [
        'slow_ms'       => 1000,
        'slow_query_ms' => 250,
        'raw_days'      => 14,
        'rollup_days'   => 180,
        'server_source' => 'none',
        'report_to'     => 'Admin',
        'snapshot_path' => '',
    ];

    /** Cached manifest contents (without defaults merged in), or null = not loaded yet. */
    private static ?array $cache = null;

    /**
     * Test seam: when set, get()/all() read from here instead of the
     * manifest file — no _BASE_DIR, no filesystem. Does NOT change what
     * enabled() reports (that stays tied to the real file), so a test that
     * needs enabled() === true/false controls it via _BASE_DIR instead.
     */
    private static ?array $overrides = null;

    /**
     * Test seam: when non-null, enabled() returns this instead of checking
     * the manifest — lets a middleware test exercise the recording path
     * without defining _BASE_DIR (which the Ops tests must never do).
     * Cleared by reset().
     */
    private static ?bool $forcedEnabled = null;

    /** The behavior is declared for this project (the manifest exists). */
    public static function enabled(): bool
    {
        if (self::$forcedEnabled !== null) {
            return self::$forcedEnabled;
        }

        return \defined('_BASE_DIR') && \is_file(self::path());
    }

    /** Test seam: force enabled() to $v (null = back to the manifest check). */
    public static function forceEnabled(?bool $v): void
    {
        self::$forcedEnabled = $v;
    }

    /**
     * A single config value: the override (if set), else the manifest value
     * (if present), else the built-in default for that key.
     *
     * @return mixed
     */
    public static function get(string $k)
    {
        return self::all()[$k] ?? (self::DEFAULTS[$k] ?? null);
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$overrides !== null) {
            return \array_merge(self::DEFAULTS, self::$overrides);
        }

        return \array_merge(self::DEFAULTS, self::manifest());
    }

    /**
     * Test seam: force get()/all() to read $values (merged over the
     * defaults) instead of the manifest file. Pass null to go back to
     * reading the real manifest. Reset in tearDown.
     *
     * @param array<string,mixed>|null $values
     */
    public static function override(?array $values): void
    {
        self::$overrides = $values;
    }

    /** Test seam: drop the cached manifest and any override. */
    public static function reset(): void
    {
        self::$cache = null;
        self::$overrides = null;
        self::$forcedEnabled = null;
    }

    /** @return array<string,mixed> raw manifest contents, [] when there is none */
    private static function manifest(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            // The real file check, never the forceEnabled() seam — a forced
            // test must not dereference an undefined _BASE_DIR here.
            if (\defined('_BASE_DIR') && \is_file(self::path())) {
                $m = require self::path();
                if (\is_array($m)) {
                    self::$cache = $m;
                }
            }
        }

        return self::$cache;
    }

    private static function path(): string
    {
        return _BASE_DIR . 'config/Built/ops_monitor.php';
    }
}
