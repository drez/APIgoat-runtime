<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Refresh-token mint / redeem / rotate / reuse-detection / revocation.
 *
 * Pure logic over a RefreshTokenStore seam + injected clock + injected
 * JWT minter, so the core is unit-testable with no DB and no \App\ classes.
 * Production wiring builds the Propel-backed store via self::forProject().
 */
final class RefreshTokenService
{
    // Fallback defaults when neither the GC_SESSION_API_DAYS knob nor
    // jwt_middleware.refresh_expire / .refresh_family_expire settings exist.
    const DEFAULT_REFRESH_EXPIRE        = 'now +90 days';
    const DEFAULT_REFRESH_FAMILY_EXPIRE = 'now +90 days';

    const THROTTLE_WINDOW = 60;   // seconds
    const THROTTLE_MAX    = 20;   // redeem attempts per ip-or-family per window

    /** @var callable():int */
    private $clock;

    public function __construct(
        private RefreshTokenStore $store,
        private array $jwt = [],
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Build the production service against Propel, or null when the model is absent
     * (project without the with_refresh_tokens parameter). No-op safe.
     */
    public static function forProject(): ?self
    {
        if (!class_exists('\App\AuthyRefreshToken')) {
            return null;
        }
        $settings = new \Selective\Config\Configuration(\ApiGoat\Utility\Settings::load());
        $jwt = $settings->getArray('jwt_middleware');
        return new self(new PropelRefreshTokenStore(), $jwt);
    }

    public function mintForLogin(int $idAuthy): string
    {
        $now = ($this->clock)();
        [$raw, $hash] = $this->generate();
        $this->store->insert([
            'id_authy'       => $idAuthy,
            'family_id'      => $this->newFamilyId(),
            'token_hash'     => $hash,
            'expires'        => $this->ts($this->refreshExpire(), $now),
            'family_expires' => $this->ts($this->familyExpire(), $now),
        ]);
        return $raw;
    }

    /**
     * @param callable $mintAccessToken fn(int $idAuthy): array{token:string,expires:int,status:string}
     * @return array{status:string,token?:string,expires?:int,refresh_token?:string,message?:string}
     */
    public function redeem(string $rawToken, string $ip, callable $mintAccessToken): array
    {
        $now = ($this->clock)();
        if ($rawToken === '') {
            return $this->err('invalid_token');
        }

        $row = $this->store->findByHash($this->hashToken($rawToken));
        $familyId = $row['family_id'] ?? '';

        // throttle BEFORE doing any state change
        if ($this->store->recentAttemptCount($ip, $familyId, $now - self::THROTTLE_WINDOW) >= self::THROTTLE_MAX) {
            return $this->err('rate_limited');
        }
        $this->store->recordAttempt($ip, $familyId, $now);

        if ($row === null) {
            return $this->err('invalid_token');
        }
        if ($row['revoked'] === 'Yes') {
            $this->store->revokeFamily($row['family_id']);   // reuse attack
            return $this->err('token_reuse');
        }
        if ($row['expires'] < $now || $row['family_expires'] < $now) {
            $this->store->markRevoked($row['id'], $now);
            return $this->err('expired');
        }

        // Mint the access token BEFORE mutating any state. If the minter fails
        // (e.g. the Authy row was deleted), we return an error without revoking
        // or rotating — the client still holds a usable refresh token.
        $jwt = $mintAccessToken($row['id_authy']);
        if (($jwt['status'] ?? '') !== 'success' || empty($jwt['token'])) {
            return $this->err('invalid_token');
        }

        // rotate
        $this->store->markRevoked($row['id'], $now);
        [$raw2, $hash2] = $this->generate();
        $newExpires = min(
            $this->ts($this->refreshExpire(), $now),
            $row['family_expires']
        );
        $this->store->insert([
            'id_authy'       => $row['id_authy'],
            'family_id'      => $row['family_id'],
            'token_hash'     => $hash2,
            'expires'        => $newExpires,
            'family_expires' => $row['family_expires'],
        ]);

        return [
            'status'        => 'success',
            'token'         => $jwt['token'],
            'expires'       => $jwt['expires'],
            'refresh_token' => $raw2,
        ];
    }

    public function revokeFamily(string $familyId): void
    {
        $this->store->revokeFamily($familyId);
    }

    public function revokeAllForUser(int $idAuthy): void
    {
        $this->store->revokeAllForUser($idAuthy);
    }

    /**
     * Session-length resolution: GC_SESSION_API_DAYS knob (project .env,
     * clamped to 365) > jwt_middleware settings > 90-day default. Env-first
     * because per-project settings.defaults.php copies are not drift-synced —
     * the knob must work on projects whose settings still carry old values.
     * With the knob set, refresh and family expire together: the API session
     * is an absolute N-day window from login.
     */
    private function refreshExpire(): string|int
    {
        $days = SessionLifetime::apiDaysFromEnv();
        if ($days !== null) {
            return 'now +' . $days . ' days';
        }
        return $this->jwt['refresh_expire'] ?? self::DEFAULT_REFRESH_EXPIRE;
    }

    private function familyExpire(): string|int
    {
        $days = SessionLifetime::apiDaysFromEnv();
        if ($days !== null) {
            return 'now +' . $days . ' days';
        }
        return $this->jwt['refresh_family_expire'] ?? self::DEFAULT_REFRESH_FAMILY_EXPIRE;
    }

    /** Resolve a DateTime-string OR integer-seconds TTL to a unix timestamp, anchored to $now. */
    private function ts(string|int $expr, int $now): int
    {
        if (is_int($expr) || (is_string($expr) && ctype_digit($expr))) {
            return $now + (int) $expr;
        }
        $ts = strtotime((string) $expr, $now);
        return $ts !== false ? $ts : $now;
    }

    /** @return array{0:string,1:string} [raw, sha256hash] */
    private function generate(): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$raw, $this->hashToken($raw)];
    }

    private function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private function newFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function err(string $message): array
    {
        return ['status' => 'error', 'message' => $message];
    }
}
