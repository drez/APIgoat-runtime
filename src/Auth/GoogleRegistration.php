<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Self-registration policy for Google sign-in (GIS ID-token flow).
 *
 * Read from the project .env (OFF by default — the emitted Authy/google
 * handler only ever LINKS existing users unless this says otherwise):
 *
 *   GOOGLE_AUTO_REGISTER="1"                        1/true/yes/on enables
 *   GOOGLE_AUTO_REGISTER_GROUP="Learner"            authy_group NAME for new
 *                                                   users; unset/unknown →
 *                                                   the seeded default group
 *   GOOGLE_AUTO_REGISTER_DOMAINS="a.com,b.org"      email-domain allowlist;
 *                                                   empty → any verified
 *                                                   Google account
 *
 * Pure value object: no DB, no session. Group NAME → id resolution lives in
 * the emitted handler (it needs the project's Propel classes).
 */
final class GoogleRegistration
{
    private const KEY_ENABLED = 'GOOGLE_AUTO_REGISTER';
    private const KEY_GROUP   = 'GOOGLE_AUTO_REGISTER_GROUP';
    private const KEY_DOMAINS = 'GOOGLE_AUTO_REGISTER_DOMAINS';

    /** @param list<string> $domains lower-cased, trimmed, non-empty */
    private function __construct(
        private readonly bool $enabled,
        private readonly ?string $groupName,
        private readonly array $domains,
    ) {
    }

    public static function fromEnv(): self
    {
        $enabled = in_array(strtolower(self::env(self::KEY_ENABLED)), ['1', 'true', 'yes', 'on'], true);

        $group = self::env(self::KEY_GROUP);
        $groupName = $group === '' ? null : $group;

        $domains = [];
        foreach (explode(',', strtolower(self::env(self::KEY_DOMAINS))) as $d) {
            $d = trim($d);
            if ($d !== '' && !in_array($d, $domains, true)) {
                $domains[] = $d;
            }
        }

        return new self($enabled, $groupName, $domains);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function groupName(): ?string
    {
        return $this->groupName;
    }

    /** @return list<string> */
    public function domains(): array
    {
        return $this->domains;
    }

    /** Enabled AND (no allowlist OR the email's domain is an exact allowlist entry). */
    public function allowsEmail(string $email): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $at = strrpos($email, '@');
        if ($at === false || $at === strlen($email) - 1) {
            return false;
        }
        if ($this->domains === []) {
            return true;
        }
        return in_array(strtolower(substr($email, $at + 1)), $this->domains, true);
    }

    /**
     * $_ENV first (adhocore/env populates it from the project .env), getenv()
     * fallback — the same lookup order the emitted GOOGLE_CLIENT_ID read uses.
     * Surrounding quotes are stripped; the value is trimmed.
     */
    private static function env(string $key): string
    {
        $raw = $_ENV[$key] ?? getenv($key);
        if ($raw === false || $raw === null) {
            return '';
        }
        $raw = trim((string) $raw);
        if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
            $raw = trim(substr($raw, 1, -1));
        }
        return $raw;
    }
}
