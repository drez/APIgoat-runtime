<?php

namespace ApiGoat\Notify;

/**
 * Reader for the build-emitted notify manifest (config/Built/notify.php).
 * Mirror of ApiGoat\Pdf\PdfManifest / ApiGoat\Ai\AiManifest.
 */
final class NotifyManifest
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            if (\defined('_BASE_DIR') && \is_file(_BASE_DIR . 'config/Built/notify.php')) {
                $m = require _BASE_DIR . 'config/Built/notify.php';
                if (\is_array($m)) {
                    self::$cache = $m;
                }
            }
        }

        return self::$cache;
    }

    public static function available(): bool
    {
        return self::all() !== [];
    }

    /**
     * Reminder specs declared by with_notify, keyed by table.
     *
     * The manifest carries only the DECLARATIVE half (table, date column,
     * offsets, stamp column, log table). The callables a sweep needs —
     * recipient resolution and message composition — stay in project code and
     * are merged in by the caller, because they are the genuinely
     * project-specific part and cannot be emitted.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function reminders(): array
    {
        $r = self::all()['reminders'] ?? [];

        return \is_array($r) ? $r : [];
    }

    /** @return array<string,mixed>|null */
    public static function reminder(string $table): ?array
    {
        return self::reminders()[$table] ?? null;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
