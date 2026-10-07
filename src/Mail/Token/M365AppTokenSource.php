<?php

namespace ApiGoat\Mail\Token;

use ApiGoat\Google\HttpTransport;
use ApiGoat\Mail\TokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Microsoft\GraphErrorMapper;
use ApiGoat\Sync\Exceptions\RateLimited;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * App-only Microsoft 365 access: our multi-tenant app's client credentials
 * against the customer's tenant (needs admin consent). Tokens are cached in
 * process until ~60 s before expiry.
 */
final class M365AppTokenSource implements TokenSource
{
    private const CONSENT_CODES = ['AADSTS7000229', 'AADSTS65001', 'AADSTS700016'];

    /** @var callable */
    private $transport;
    private ?string $token = null;
    private int $expiresAt = 0;

    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret,
        ?callable $transport = null,
    ) {
        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new AuthFailed('M365AppTokenSource needs tenant_id, client_id and client_secret');
        }
        $this->transport = $transport ?? new HttpTransport(15, 15);
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
        return 'm365app:' . $this->tenantId;
    }

    private function refresh(): void
    {
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);
        $r      = ($this->transport)('POST', "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", ['Content-Type: application/x-www-form-urlencoded'], $body);
        $status = (int) $r['status'];
        $data   = json_decode((string) $r['body'], true);
        $data   = is_array($data) ? $data : [];
        if ($status === 429) {
            throw new RateLimited('Microsoft token endpoint throttled', GraphErrorMapper::retryAfter((string) ($r['headers'] ?? '')), null, 429);
        }
        if ($status >= 500) {
            throw new TransientError('Microsoft token endpoint HTTP ' . $status, $status);
        }
        if ($status !== 200 || empty($data['access_token'])) {
            $desc = (string) ($data['error_description'] ?? '');
            foreach (self::CONSENT_CODES as $c) {
                if (str_contains($desc, $c)) {
                    throw new AuthFailed("Microsoft 365 admin consent missing for tenant {$this->tenantId}", $status);
                }
            }
            $msg = $desc !== '' ? $desc : (string) ($data['error'] ?? "HTTP {$status}");
            throw new AuthFailed('Microsoft 365 token failed for ' . $this->describe() . ": {$msg}", $status);
        }
        $this->token     = (string) $data['access_token'];
        $this->expiresAt = time() + (int) ($data['expires_in'] ?? 3600);
    }
}
