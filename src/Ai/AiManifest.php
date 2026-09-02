<?php

namespace ApiGoat\Ai;

/**
 * Reader for the build-emitted AI manifest (config/Built/ai.php).
 * Mirror of ApiGoat\Pdf\PdfManifest / ApiGoat\Stripe\StripeManifest:
 * everything AI is gated on available().
 */
final class AiManifest
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (\defined('_BASE_DIR') && \is_file(_BASE_DIR . 'config/Built/ai.php')) {
                $m = require _BASE_DIR . 'config/Built/ai.php';
                if (\is_array($m)) {
                    self::$cache = $m;
                }
            }
        }

        return self::$cache;
    }

    /** The behavior is declared for this project (the manifest exists). */
    public static function available(): bool
    {
        return self::all() !== [];
    }

    public static function baseUrl(): string
    {
        return (string) (self::all()['base_url'] ?? 'https://api.openai.com/v1');
    }

    /** Name of the emitted call-log table, or '' when logging is off. */
    public static function logTable(): string
    {
        return (string) (self::all()['log_table'] ?? '');
    }

    /** Default per-call timeout in seconds. */
    public static function timeout(): int
    {
        return (int) (self::all()['timeout'] ?? 30);
    }

    /** Minimum spacing between outbound calls, in seconds (0 disables). */
    public static function throttleSeconds(): float
    {
        return (float) (self::all()['throttle'] ?? 0.25);
    }

    public static function retries(): int
    {
        return (int) (self::all()['retries'] ?? 2);
    }

    /** @return array<string,mixed> */
    public static function quota(): array
    {
        $q = self::all()['quota'] ?? [];

        return \is_array($q) ? $q : [];
    }

    /** Env var name holding the API key, and the `config` row to check first. */
    public static function keyEnv(): string
    {
        return (string) (self::all()['key_env'] ?? 'OPENAI_API_KEY');
    }

    public static function keyConfigRow(): string
    {
        return (string) (self::all()['key_config'] ?? 'openai_api_key');
    }

    /**
     * The with_ai `chat` declaration, or null when the project declared none.
     *
     * @return array{table:string,model:string,label:string,persona:string}|null
     */
    public static function chat(): ?array
    {
        $c = self::all()['chat'] ?? null;
        if (!\is_array($c) || empty($c['model'])) {
            return null;
        }

        return [
            'table'   => (string) ($c['table'] ?? ''),
            'model'   => (string) $c['model'],
            'label'   => (string) ($c['label'] ?? 'Ask AI'),
            'persona' => (string) ($c['persona'] ?? ''),
        ];
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
