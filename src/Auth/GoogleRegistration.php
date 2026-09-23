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

    /**
     * Enabled AND (no allowlist OR the email's domain is an exact allowlist
     * entry). Email-only: does NOT prove Workspace membership — callers
     * holding the verified token claims must use allowsClaims().
     */
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

    /** Consumer Google domains: accounts there never carry an `hd` claim. */
    private const CONSUMER_DOMAINS = ['gmail.com', 'googlemail.com'];

    /**
     * Claims-aware variant of allowsEmail() — prefer it. With a domain
     * allowlist, the verified ID token's `hd` (hosted domain) claim must
     * equal the email's domain: anyone can create an unmanaged consumer
     * Google account on any address (email_verified=true, no `hd`), so the
     * email domain alone does not prove Workspace membership. Consumer
     * domains (gmail.com) have no `hd` and are matched on the email alone.
     *
     * @param array<string,mixed> $claims verified claims from GoogleIdToken::verify()
     */
    public function allowsClaims(array $claims): bool
    {
        $email = (string) ($claims['email'] ?? '');
        if (!$this->allowsEmail($email)) {
            return false;
        }
        if ($this->domains === []) {
            return true;
        }
        $domain = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        if (in_array($domain, self::CONSUMER_DOMAINS, true)) {
            return true;
        }
        $hd = strtolower(trim((string) ($claims['hd'] ?? '')));
        return $hd !== '' && $hd === $domain;
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
