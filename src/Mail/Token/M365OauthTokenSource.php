<?php

namespace ApiGoat\Mail\Token;

use ApiGoat\Google\HttpTransport;
use ApiGoat\Mail\TokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Microsoft\GraphErrorMapper;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Delegated Microsoft 365 access: the user signed in once, we hold a refresh
 * token. Microsoft rotates refresh tokens, so a new one is handed to
 * $onRotate BEFORE it is used or the access token is returned; losing it
 * would lock the mailbox out.
 */
final class M365OauthTokenSource implements TokenSource
{
    public const TOKEN_URL = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/token';
    public const SCOPES    = 'openid email offline_access User.Read Mail.ReadWrite Mail.Send Contacts.Read People.Read Calendars.ReadWrite';

    /** @var callable */
    private $transport;
    /** @var callable|null fn(string $newRefreshToken): void */
    private $onRotate;
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $refreshToken,
        private string $email = '',
        ?callable $transport = null,
        ?callable $onRotate = null,
    ) {
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new AuthFailed('M365OauthTokenSource needs client_id, client_secret and refresh_token');
        }
        $this->transport = $transport ?? new HttpTransport(15, 15);
        $this->onRotate  = $onRotate;
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
        return 'm365oauth:' . $this->email;
    }

    private function refresh(): void
    {
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'scope'         => self::SCOPES,
        ]);
        $r      = ($this->transport)('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
        $status = (int) $r['status'];
        $data   = json_decode((string) $r['body'], true);
        $data   = is_array($data) ? $data : [];
        if ($status === 429) {
            throw new RateLimited('Microsoft token endpoint throttled', GraphErrorMapper::retryAfter((string) ($r['headers'] ?? '')));
        }
        if ($status >= 500) {
            throw new TransientError('Microsoft token endpoint HTTP ' . $status, $status);
        }
        if ($status !== 200 || empty($data['access_token'])) {
            if (($data['error'] ?? '') === 'invalid_grant') {
                throw new AuthFailed("Microsoft 365 sign-in expired for {$this->email}: sign in again", 401);
            }
            $msg = (string) ($data['error_description'] ?? $data['error'] ?? "HTTP {$status}");
            throw new AuthFailed('Microsoft 365 refresh failed for ' . $this->describe() . ": {$msg}", $status);
        }
        $new = (string) ($data['refresh_token'] ?? '');
        if ($new !== '' && $new !== $this->refreshToken) {
            if ($this->onRotate) {
                ($this->onRotate)($new);
            }
            $this->refreshToken = $new;
        }
        $this->token     = (string) $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 3600);
    }
}
