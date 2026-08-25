<?php

namespace ApiGoat\Ai;

/**
 * One resolution ladder for the AI API key and model settings.
 *
 * Four different ladders were in use across the fleet before this existed:
 *
 *   aexpert/apigoatacc/ut911  env() ?? $_ENV ?? getenv() ?? defined()
 *   apicrm                    getenv() ?: $_ENV
 *   apigTutor                 a `config` DB row, memoized per request
 *   apichatbot                a `config` DB row, one query per lookup
 *
 * so the same project could resolve a key in a CLI script and fail in a web
 * request, or vice versa. The order here is deliberate: an editable `config`
 * row wins, because that is the one an operator can change without a deploy;
 * the environment is the fallback.
 */
final class AiConfig
{
    /** @var array<string,string>|null key -> non-blank value, loaded once per request */
    private static ?array $configMemo = null;

    /**
     * Drop the per-request memo.
     *
     * Needed by anything that WRITES a `config` row and reads it back in the
     * same process — tests, and bin/ scripts that seed config. A caller that
     * forgets this reads its own pre-write value, which is the one trap
     * memoizing introduces.
     */
    public static function reset(): void
    {
        self::$configMemo = null;
    }

    /**
     * Read an editable `config` row's value, or $default when absent/blank.
     *
     * ONE read of the whole (~50 row) table per request, not a query per key.
     * Blank values are dropped at load time rather than at lookup, so a row
     * that exists but is empty still falls through to $default.
     */
    public static function config(string $key, ?string $default = null): ?string
    {
        if (self::$configMemo === null) {
            if (!\class_exists('\App\ConfigQuery')) {
                return $default;
            }
            try {
                $memo = [];
                foreach (\App\ConfigQuery::create()->find() as $row) {
                    $v = $row->getValue();
                    if ($v !== null && \trim((string) $v) !== '') {
                        $memo[(string) $row->getConfig()] = (string) $v;
                    }
                }
                self::$configMemo = $memo;
            } catch (\Throwable $e) {
                // config table/model unavailable — return the default WITHOUT
                // memoizing, so a transient failure cannot pin every later
                // read in this request to its default.
                return $default;
            }
        }

        return self::$configMemo[$key] ?? $default;
    }

    /**
     * The API key, or '' when unset.
     *
     * Callers should treat '' as "AI not configured" and degrade, not throw:
     * a missing key is an operator state, not a bug.
     */
    public static function apiKey(): string
    {
        $fromConfig = self::config(AiManifest::keyConfigRow());
        if (\is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        return self::fromEnv(AiManifest::keyEnv());
    }

    /**
     * Read one environment value across every mechanism the fleet uses.
     *
     * env() is the project helper; $_ENV and getenv() disagree depending on
     * SAPI and variables_order; a defined() constant is how some older
     * projects inject secrets. Checking all four is why this is centralised.
     */
    public static function fromEnv(string $name): string
    {
        if (\function_exists('env')) {
            $v = env($name);
            if (\is_string($v) && $v !== '') {
                return $v;
            }
        }
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }
        $v = \getenv($name);
        if (\is_string($v) && $v !== '') {
            return $v;
        }
        if (\defined($name)) {
            $v = \constant($name);
            if (\is_string($v) && $v !== '') {
                return $v;
            }
        }

        return '';
    }

    /** Token cost in USD: tokens x price-per-1M, summed over input and output. */
    public static function priceOf(int $in, int $out, float $inPrice, float $outPrice): float
    {
        return $in / 1_000_000.0 * $inPrice + $out / 1_000_000.0 * $outPrice;
    }
}
