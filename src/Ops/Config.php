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
 *   report_to, snapshot_path, and the generic log windows (days, 0 = keep
 *   forever) authy_log_days, api_log_days, client_event_days (90) and
 *   contact_message_days (365) — a manifest built before those keys
 *   existed simply falls back to DEFAULTS.
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
        'hub'           => false,
        // route => ms: routes slow by design (LLM, MCP) only count as slow
        // above their own threshold; a key ending in '*' is a prefix.
        'slow_routes'   => [],
        // Generic log retention (Retention::pruneLogs), days; 0 = keep forever.
        'authy_log_days'       => 90,
        'api_log_days'         => 90,
        'client_event_days'    => 90,
        'contact_message_days' => 365,
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

    /**
     * The slow threshold for $route (a matched route pattern): its exact
     * slow_routes entry, else the longest matching 'prefix*' entry, else
     * slow_ms. Non-positive / non-integer entries are ignored.
     */
    public static function slowMsFor(?string $route): int
    {
        $default = (int) self::get('slow_ms');
        $routes = self::get('slow_routes');
        if ($route === null || !\is_array($routes) || $routes === []) {
            return $default;
        }
        $best = null;
        $bestLen = -1;
        foreach ($routes as $key => $ms) {
            $key = (string) $key;
            if (!\is_int($ms) || $ms <= 0) {
                continue;
            }
            if ($key === $route) {
                return $ms;
            }
            if (\str_ends_with($key, '*') && \str_starts_with($route, \substr($key, 0, -1)) && \strlen($key) > $bestLen) {
                $best = $ms;
                $bestLen = \strlen($key);
            }
        }

        return $best ?? $default;
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
    /** Is this app the hub that receives the other apps' telemetry (with_ops_monitor hub: true)? */
    public static function isHub(): bool
    {
        return self::get('hub') === true;
    }

    /** Hub ingest URL (env GC_OPS_HUB_URL), https only; null = do not forward. */
    public static function hubUrl(): ?string
    {
        $url = self::env('GC_OPS_HUB_URL');

        return $url !== null && \str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Only a deployed app reports: `gc deploy` writes VERSION=production into
     * the remote .env, and a local checkout has no VERSION (or another one),
     * so dev data never reaches the hub even though `gc build` puts the
     * collector settings in the project .env.
     */
    public static function isProduction(): bool
    {
        return self::env('VERSION') === 'production';
    }

    /** This app's hub site secret (env GC_OPS_HUB_KEY, an ana_site sk_ key). */
    public static function hubKey(): ?string
    {
        return self::env('GC_OPS_HUB_KEY');
    }

    /**
     * On the hub: the ana_site id that IS this app (env GC_OPS_SELF_SITE), so
     * selecting it in the dashboards also shows the hub's own site_id = 0
     * rows. Null when unset.
     */
    public static function selfSite(): ?int
    {
        $v = self::env('GC_OPS_SELF_SITE');

        return $v !== null && \ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }

    private static function env(string $key): ?string
    {
        $v = \function_exists('env') ? env($key) : \getenv($key);
        if (!\is_string($v) || $v === '') {
            $v = $_ENV[$key] ?? null;
        }

        return \is_string($v) && \trim($v) !== '' ? \trim($v) : null;
    }

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
