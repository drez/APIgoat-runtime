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

    /**
     * @param ?string $userAgent  device label source; null = this request's User-Agent
     * @param ?string $ip         client address; null = this request's REMOTE_ADDR
     */
    public function mintForLogin(int $idAuthy, ?string $userAgent = null, ?string $ip = null): string
    {
        $now = ($this->clock)();
        [$raw, $hash] = $this->generate();
        $this->store->insert([
            'id_authy'       => $idAuthy,
            'family_id'      => $this->newFamilyId(),
            'token_hash'     => $hash,
            'expires'        => $this->ts($this->refreshExpire(), $now),
            'family_expires' => $this->ts($this->familyExpire(), $now),
        ] + $this->device($userAgent, $ip));
        return $raw;
    }

    /** @return array{user_agent:?string,ip:?string} the latest request's device fields (bounded) */
    private function device(?string $userAgent, ?string $ip): array
    {
        $ua = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return [
            'user_agent' => $ua !== '' ? mb_substr($ua, 0, 255) : null,
            'ip'         => $ip !== '' ? mb_substr($ip, 0, 45) : null,
        ];
    }

    /**
     * @param callable $mintAccessToken fn(int $idAuthy): array{token:string,expires:int,status:string}
     * @return array{status:string,token?:string,expires?:int,refresh_token?:string,message?:string}
     */
    public function redeem(string $rawToken, string $ip, callable $mintAccessToken, ?string $userAgent = null): array
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
            return $this->graceReplay($rawToken, $row, $mintAccessToken, $now);
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
            return $this->graceReplay($rawToken, $row, static fn () => $jwt, $now);
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
        ] + $this->device($userAgent, $ip));

        // A family revocation (sign-out, logout, password change, reuse
        // detection) that landed between our claim and this insert could not
        // see the successor. Every revocation tombstones the family
        // (family_expires = 0 on ALL its rows, the claimed parent included),
        // so re-read the parent now: either the revoke committed before this
        // read and we see the tombstone, or it runs after the insert and its
        // UPDATE revokes the successor itself.
        $parent = $this->store->findByHash($this->hashToken($rawToken));
        if ($parent === null || (int) $parent['family_expires'] < $now) {
            $this->store->revokeFamily($row['family_id']);
            return $this->err('token_reuse');
        }

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
     *
     * Never past exp (review-3 #7): the grace path is reached BEFORE the
     * normal expiry check, and the expiry branch itself stamps last_used_at
     * (markRevoked) — so without this guard an expired token presented twice
     * within REUSE_GRACE was redeemed for a fresh access JWT. Both the
     * presented token and (when already inserted) its successor must be
     * inside their per-token AND family expiry.
     */
    private function graceReplay(string $rawToken, array $row, callable $mintAccessToken, int $now): array
    {
        if ($this->secret() === '') {
            return $this->err('token_reuse');
        }
        if ($this->isPastExpiry($row, $now)) {
            return $this->err('expired');
        }
        [$raw2, $hash2] = $this->successor($rawToken);
        $succ = $this->store->findByHash($hash2);
        if ($succ !== null && $succ['revoked'] === 'Yes' && ($succ['last_used_at'] ?? null) === null) {
            return $this->err('token_reuse');   // family was revoked after the rotation
        }
        if ($succ !== null && $this->isPastExpiry($succ, $now)) {
            return $this->err('expired');
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

    /** True when a store row is past its per-token OR family expiry (unix ts). */
    private function isPastExpiry(array $row, int $now): bool
    {
        return (int) ($row['expires'] ?? 0) < $now || (int) ($row['family_expires'] ?? 0) < $now;
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
     * The family of a presented refresh token, only when the token is the
     * CURRENT token of one of the user's live families. A token rotated away
     * resolves only within REUSE_GRACE of its rotation (the same benign race
     * redeem() tolerates); later it is a replayed (possibly stolen) token:
     * like redeem(), its family is revoked and null is returned, so an old
     * token can never pick the family a password change or "sign out other
     * devices" keeps. Null too for an unknown, foreign or expired token.
     */
    public function liveFamilyOf(int $idAuthy, string $rawToken): ?string
    {
        if ($rawToken === '' || $idAuthy <= 0) {
            return null;
        }
        $row = $this->store->findByHash($this->hashToken($rawToken));
        if ($row === null || (int) $row['id_authy'] !== $idAuthy) {
            return null;
        }
        $now = ($this->clock)();
        if ($row['revoked'] === 'Yes') {
            $lastUsed = $row['last_used_at'] ?? null;
            if ($lastUsed === null || ($now - (int) $lastUsed) > self::REUSE_GRACE) {
                $this->store->revokeFamily($row['family_id']);   // reuse: same response as redeem()
                return null;
            }
        } elseif ($row['expires'] < $now || $row['family_expires'] < $now) {
            return null;
        }
        foreach ($this->store->liveFamilies($idAuthy, ($this->clock)()) as $f) {
            if ($f['family_id'] === $row['family_id']) {
                return $row['family_id'];
            }
        }
        return null;
    }

    /**
     * The user's live sessions (one per refresh-token family), the family of
     * $currentRaw first, then last used (never used = created) newest first.
     * `id` is the opaque handle sessions can be revoked by (see revokeSession).
     *
     * @return list<array{id:string,created:int,last_used:?int,expires:int,device:string,ip:?string,current:bool}>
     */
    public function sessionsFor(int $idAuthy, ?string $currentRaw = null): array
    {
        $current = ($currentRaw !== null && $currentRaw !== '') ? $this->liveFamilyOf($idAuthy, $currentRaw) : null;
        $out = [];
        foreach ($this->store->liveFamilies($idAuthy, ($this->clock)()) as $f) {
            $out[] = [
                'id'        => (string) $f['id'],
                'created'   => (int) $f['created'],
                'last_used' => $f['last_used'],
                'expires'   => (int) $f['expires'],
                'device'    => DeviceLabel::fromUserAgent($f['user_agent'] ?? null),
                'ip'        => DeviceLabel::maskIp($f['ip'] ?? null),
                'current'   => $current !== null && $f['family_id'] === $current,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['current'] !== $b['current']) {
                return $a['current'] ? -1 : 1;
            }
            return ($b['last_used'] ?? $b['created']) <=> ($a['last_used'] ?? $a['created']) ?: ((int) $b['id'] <=> (int) $a['id']);
        });
        return $out;
    }

    /**
     * Revoke one of the user's live sessions by the id sessionsFor() handed
     * out. False when it is not a live session of THIS user (unknown, foreign,
     * already revoked): nothing is touched. Access JWTs already issued to that
     * device keep working until they expire (session_epoch is not bumped).
     */
    public function revokeSession(int $idAuthy, string $id): bool
    {
        if ($id === '' || !ctype_digit($id)) {
            return false;
        }
        foreach ($this->store->liveFamilies($idAuthy, ($this->clock)()) as $f) {
            if ((string) $f['id'] === $id) {
                $this->store->revokeFamily($f['family_id']);
                return true;
            }
        }
        return false;
    }

    /**
     * Revoke every live session of the user except $keepFamilyId.
     * @return int number of live sessions revoked
     */
    public function revokeOtherSessions(int $idAuthy, string $keepFamilyId): int
    {
        $n = 0;
        foreach ($this->store->liveFamilies($idAuthy, ($this->clock)()) as $f) {
            if ($f['family_id'] !== $keepFamilyId) {
                $n++;
            }
        }
        $this->store->revokeAllForUserExcept($idAuthy, $keepFamilyId);
        return $n;
    }

    /** Revoke all of the user's families but the one of $rawToken; false when that is not the user's live family. */
    public function revokeAllExceptTokenFamily(int $idAuthy, string $rawToken): bool
    {
        $keep = $this->liveFamilyOf($idAuthy, $rawToken);
        if ($keep === null) {
            return false;
        }
        $this->store->revokeAllForUserExcept($idAuthy, $keep);
        return true;
    }

    /**
     * Sign-out for an API client: revoke the whole family of the presented
     * refresh token (every rotation of that login, including a successor
     * minted from it). Idempotent and oracle-free: an unknown or already
     * revoked token answers success too — the caller is signing out either
     * way. A stale (already rotated) token still finds its row, so it still
     * ends the live rotation. Throttled with the same window and counter as
     * redeem(), so it cannot probe token hashes faster than refresh can.
     * Nothing here logs the token.
     *
     * @return array{status:string,message?:string}
     */
    public function revokeByToken(string $rawToken, string $ip): array
    {
        $now = ($this->clock)();
        if ($rawToken === '') {
            return $this->err('invalid_token');
        }
        $row      = $this->store->findByHash($this->hashToken($rawToken));
        $familyId = $row['family_id'] ?? '';
        if ($this->store->recentAttemptCount($ip, $familyId, $now - self::THROTTLE_WINDOW) >= self::THROTTLE_MAX) {
            return $this->err('rate_limited');
        }
        $this->store->recordAttempt($ip, $familyId, $now);
        if ($row !== null) {
            $this->store->revokeFamily($row['family_id']);
        }
        return ['status' => 'success'];
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
