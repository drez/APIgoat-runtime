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

    /**
     * Concurrency grace for reuse detection (2026-07-30): several parallel
     * clients of ONE session (a web page load fires 4+ app-server requests;
     * mobile fires parallel calls too) can all present the same refresh
     * token the moment the access token expires. The first redeem rotates
     * it; without a grace window every later redeem tripped reuse
     * detection and revoked the WHOLE family — killing the session exactly
     * when it should have refreshed (overnight hard-401s, seen live on
     * vidifye). A token rotated less than REUSE_GRACE seconds ago is a
     * benign concurrent redeem.
     *
     * Wave 4 (2026-09-23): the grace path no longer forks the family. The
     * successor of a token is DERIVED (HMAC of the presented raw token under
     * the JWT secret), so a grace replay returns the SAME successor refresh
     * token the winning redeem issued — the family keeps exactly one live
     * token. Rotation itself is an atomic compare-and-swap
     * (RefreshTokenStore::claimRotation): of N truly concurrent redeems
     * that all read the row while live, exactly one inserts the successor;
     * the others take the grace path. Only the short-lived access JWT is
     * re-minted per caller (stateless). A replayed token gains a bounded
     * REUSE_GRACE window before family revocation; the redeem throttle
     * (THROTTLE_MAX) still applies inside it.
     */
    const REUSE_GRACE = 30;       // seconds

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
            $lastUsed = $row['last_used_at'] ?? null;
            if ($lastUsed === null || ($now - (int) $lastUsed) > self::REUSE_GRACE) {
                $this->store->revokeFamily($row['family_id']);   // reuse attack
                return $this->err('token_reuse');
            }
            // Benign concurrent redeem (see REUSE_GRACE).
            return $this->graceReplay($rawToken, $row, $mintAccessToken);
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

        // Atomic rotation claim. Losing means a concurrent redeem of the
        // same token rotated it between our read and now: it is a grace
        // replay of a just-rotated token — return that redeem's successor.
        if (!$this->store->claimRotation($row['id'], $now)) {
            $row['revoked'] = 'Yes';
            $row['last_used_at'] = $now;
            return $this->graceReplay($rawToken, $row, static fn () => $jwt);
        }

        [$raw2, $hash2] = $this->successor($rawToken);
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

    /**
     * Grace replay of a token rotated < REUSE_GRACE ago: hand back the SAME
     * successor refresh token the rotation issued (never mint a sibling),
     * plus a fresh short-lived access JWT. Refused when the successor has
     * since been revoked by a family/user revocation (logout, password
     * change, reuse detection) — only a live or itself-just-rotated
     * successor is returned. Without a JWT secret the successor is random
     * and cannot be re-derived: refuse rather than fork the family.
     */
    private function graceReplay(string $rawToken, array $row, callable $mintAccessToken): array
    {
        if ($this->secret() === '') {
            return $this->err('token_reuse');
        }
        [$raw2, $hash2] = $this->successor($rawToken);
        $succ = $this->store->findByHash($hash2);
        if ($succ !== null && $succ['revoked'] === 'Yes' && ($succ['last_used_at'] ?? null) === null) {
            return $this->err('token_reuse');   // family was revoked after the rotation
        }
        $jwt = $mintAccessToken($row['id_authy']);
        if (($jwt['status'] ?? '') !== 'success' || empty($jwt['token'])) {
            return $this->err('invalid_token');
        }
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

    /**
     * Successor of a presented refresh token: HMAC-SHA256(raw, jwt secret),
     * so a grace replay can re-derive the already-issued successor without
     * storing raw tokens. Unforgeable without the server secret; falls back
     * to a random token when no secret is configured.
     * @return array{0:string,1:string} [raw, sha256hash]
     */
    private function successor(string $rawToken): array
    {
        $secret = $this->secret();
        if ($secret === '') {
            return $this->generate();
        }
        $raw = rtrim(strtr(base64_encode(hash_hmac('sha256', 'refresh-successor|' . $rawToken, $secret, true)), '+/', '-_'), '=');
        return [$raw, $this->hashToken($raw)];
    }

    private function secret(): string
    {
        $s = $this->jwt['secret'] ?? '';
        return is_string($s) ? $s : '';
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
