<?php

namespace ApiGoat\Mail\Token;

use ApiGoat\Google\HttpTransport;
use ApiGoat\Mail\TokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Per-user OAuth: the user consented once, we hold the refresh token and
 * exchange it for short-lived access tokens against Google's token endpoint.
 *
 * Phase 1 only needs the SHAPE to exist (client id/secret/refresh token is
 * what `secret_store` will hold), so nothing reshapes when the consent flow
 * lands in phase 1.5. A refreshed access token is cached in-process until
 * ~60 s before expiry; pass $onRefresh to persist it if you want to skip the
 * exchange across processes.
 */
final class OauthTokenSource implements TokenSource
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var callable */
    private $transport;
    /** @var callable|null fn(string $accessToken, int $expiresAt): void */
    private $onRefresh;
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $refreshToken,
        private string $userEmail = '',
        ?callable $transport = null,
        ?callable $onRefresh = null,
        ?string $cachedAccessToken = null,
        int $cachedExpiresAt = 0,
    ) {
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new AuthFailed('OauthTokenSource needs client_id, client_secret and refresh_token');
        }
        $this->transport = $transport ?? new HttpTransport(15, 15);
        $this->onRefresh = $onRefresh;
        if ($cachedAccessToken !== null && $cachedAccessToken !== '') {
            $this->token     = $cachedAccessToken;
            $this->expiresAt = $cachedExpiresAt;
        }
    }

    public function accessToken(): string
    {
        if ($this->token !== null && $this->expiresAt > time() + 60) {
            return $this->token;
        }
        $this->refresh();
        return (string) $this->token;
    }

    public function invalidate(): void
    {
        $this->token     = null;
        $this->expiresAt = 0;
    }

    public function describe(): string
    {
        return 'oauth:' . ($this->userEmail !== '' ? $this->userEmail : substr($this->clientId, 0, 12) . '…');
    }

    private function refresh(): void
    {
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
        ]);
        try {
            $r = ($this->transport)('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
        } catch (TransientError $e) {
            throw $e; // the queue retries network failures
        }
        $data = json_decode((string) $r['body'], true);
        $data = is_array($data) ? $data : [];
        if ((int) $r['status'] >= 500) {
            throw new TransientError('Google token endpoint HTTP ' . $r['status'], (int) $r['status']);
        }
        if ((int) $r['status'] !== 200 || empty($data['access_token'])) {
            // invalid_grant = consent revoked / refresh token expired: needs a new consent, not a retry.
            $msg = $data['error_description'] ?? $data['error'] ?? "HTTP {$r['status']}";
            throw new AuthFailed('OAuth refresh failed for ' . $this->describe() . ": {$msg}", (int) $r['status']);
        }
        $this->token     = (string) $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 3600);
        if ($this->onRefresh) {
            ($this->onRefresh)($this->token, $this->expiresAt);
        }
    }
}
