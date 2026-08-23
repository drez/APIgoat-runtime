<?php

namespace ApiGoat\Utility;

/**
 * Per-request span accumulator feeding the Server-Timing response header.
 *
 * Static because the things worth timing (an outbound OpenAI call, a slow
 * query) sit deep inside services that have no route to a request-scoped
 * object, and the alternative — threading a collector through every
 * constructor — is not worth it for a diagnostic. ServerTimingMiddleware
 * reset()s at the top of every request, so the static state never outlives
 * the request that filled it.
 */
final class Timing
{
    /** @var array<string,float> */
    private static array $spans = [];

    public static function add(string $name, float $ms): void
    {
        self::$spans[$name] = (self::$spans[$name] ?? 0.0) + $ms;
    }

    /** @return array<string,float> */
    public static function spans(): array
    {
        return self::$spans;
    }

    public static function reset(): void
    {
        self::$spans = [];
    }

    public static function header(float $totalMs): string
    {
        $parts = ['app;dur=' . number_format($totalMs, 1, '.', '')];
        foreach (self::$spans as $name => $ms) {
            $clean = self::sanitiseName((string) $name);
            if ($clean === '') {
                continue;
            }
            $parts[] = $clean . ';dur=' . number_format($ms, 1, '.', '');
        }
        return implode(', ', $parts);
    }

    /**
     * Span names land verbatim in a response header, where `,` and `;` are
     * the metric and parameter separators and CR/LF would end the header
     * outright. Every name is app-authored today; this is what keeps a name
     * that later comes from config or a route param from forging metrics or
     * injecting a header.
     */
    private static function sanitiseName(string $name): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $name), 0, 64);
    }
}
