<?php

namespace ApiGoat\Auth;

/**
 * authy.email placeholders for accounts WITHOUT a real address.
 *
 * authy.email is NOT NULL and may carry a UNIQUE index (GC_AUTHY_EMAIL_UNIQUE),
 * so an account with no email (a learner, a kid, a provisioned device user)
 * cannot store ''. The GoatCheese auth behavior stores a numbered placeholder
 * instead — `noemail-<id_authy>@<project>.invalid` (a transient
 * `noemail-pending-<hex>@<project>.invalid` inside the INSERT) — and account
 * deletion flows already write `deleted-<id>@deleted.invalid`.
 *
 * `.invalid` is reserved by RFC 2606 and never resolves, so any such address is
 * "no email": every mail path skips it (Notify\Mailer, TemplatedSend,
 * ReminderSweep, sendHTMLemail, the emitted reset/register mailers), and UI or
 * API code should display it as empty. Use this class instead of
 * `$email === ''` / `empty($email)`.
 */
final class EmailPlaceholder
{
    public const TLD = '.invalid';

    /** True for null, '' / whitespace, or any address under the reserved `.invalid` TLD. */
    public static function is(?string $email): bool
    {
        $email = \strtolower(\trim((string) $email));
        if ($email === '') {
            return true;
        }
        return \substr($email, -\strlen(self::TLD)) === self::TLD;
    }

    /** The address a human may see or use: '' for a placeholder, else the trimmed address. */
    public static function display(?string $email): string
    {
        return self::is($email) ? '' : \trim((string) $email);
    }

    /**
     * Keep the real recipients of a list (string or array; a string may be
     * `;`/`,`-separated). Placeholders are dropped and logged at info level.
     *
     * @param string|array<int,mixed>|null $to
     * @return string[]
     */
    public static function realRecipients(string|array|null $to, string $context = 'mail'): array
    {
        $list = \is_array($to) ? $to : \preg_split('/[;,]/', (string) $to);
        $out  = [];
        foreach ((array) $list as $addr) {
            if (!\is_scalar($addr)) {
                continue;
            }
            $addr = \trim((string) $addr);
            if ($addr === '') {
                continue;
            }
            if (self::is($addr)) {
                \error_log('[info] ' . $context . ': skipped placeholder recipient ' . $addr);
                continue;
            }
            $out[] = $addr;
        }
        return $out;
    }

    /** DNS-safe project slug ([a-z0-9-]), as the emitter derives it from the Propel database name. */
    public static function slug(string $name): string
    {
        $s = \trim((string) \preg_replace('/[^a-z0-9-]+/', '-', \strtolower($name)), '-');
        return $s !== '' ? \substr($s, 0, 63) : 'app';
    }

    /** `noemail-<id>@<slug>.invalid` */
    public static function forId(int $idAuthy, string $projectSlug): string
    {
        return 'noemail-' . $idAuthy . '@' . self::slug($projectSlug) . self::TLD;
    }
}
