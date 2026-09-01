<?php

namespace ApiGoat\Google;

use ApiGoat\Sync\Exceptions\AuthFailed;
use Firebase\JWT\JWT;

/**
 * Mint short-lived Google OAuth access tokens from a service account key
 * using the JWT-bearer grant, optionally impersonating a Workspace user via
 * Domain-Wide Delegation (the `sub` claim).
 *
 * Promoted from p/apicrm App\Domains\Google\JwtSigner (third copy in the
 * fleet). Config is explicit: a service-account JSON (string or decoded
 * array) or an email + PEM key. No .env parsing here — the project decides
 * where the key lives (secret_store, file, env) and hands it over.
 */
class JwtSigner
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private string $serviceEmail;
    private string $privateKey;
    /** @var callable|null */
    private $transport;

    public function __construct(string $serviceEmail, string $privateKey, ?callable $transport = null)
    {
        if ($serviceEmail === '' || $privateKey === '') {
            throw new AuthFailed('Google service account not configured: client_email and private_key are required');
        }
        $this->serviceEmail = $serviceEmail;
        $this->privateKey   = $privateKey;
        $this->transport    = $transport;
    }

    /**
     * From the JSON Google hands out when a service-account key is created
     * (`{"type":"service_account","client_email":…,"private_key":…}`) —
     * either the raw string or its decoded array.
     *
     * @param string|array<string,mixed> $json
     */
    public static function fromServiceAccount(string|array $json, ?callable $transport = null): self
    {
        $data = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($data) || empty($data['private_key']) || empty($data['client_email'])) {
            throw new AuthFailed('Invalid service account JSON: client_email and private_key are required');
        }
        return new self((string) $data['client_email'], (string) $data['private_key'], $transport);
    }

    public static function fromKeyFile(string $serviceEmail, string $keyPath, ?callable $transport = null): self
    {
        if (!is_file($keyPath)) {
            throw new AuthFailed("Service account key file not found: {$keyPath}");
        }
        $keyData = json_decode((string) file_get_contents($keyPath), true);
        if (empty($keyData['private_key'])) {
            throw new AuthFailed('Invalid service account key file: missing private_key');
        }
        return new self($serviceEmail !== '' ? $serviceEmail : (string) ($keyData['client_email'] ?? ''), (string) $keyData['private_key'], $transport);
    }

    public function serviceEmail(): string
    {
        return $this->serviceEmail;
    }

    /**
     * The signed assertion alone — exposed so tests can verify claims without
     * hitting the token endpoint.
     *
     * @param string[] $scopes
     */
    public function assertion(array $scopes, ?string $subject = null, ?int $now = null): string
    {
        $now     = $now ?? time();
        $payload = [
            'iss'   => $this->serviceEmail,
            'scope' => implode(' ', $scopes),
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        if ($subject !== null && $subject !== '') {
            $payload['sub'] = $subject;
        }
        return JWT::encode($payload, $this->privateKey, 'RS256');
    }

    /**
     * Exchange a JWT assertion for a Google OAuth access token.
     *
     * @param string[]    $scopes  Google API OAuth scopes
     * @param string|null $subject Workspace user to impersonate via DWD, or null to act as the SA itself
     * @return array{access_token: string, expires_in: int}
     */
    public function mintAccessToken(array $scopes, ?string $subject = null): array
    {
        $jwt  = $this->assertion($scopes, $subject);
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        try {
            $r = ($this->transport ?? new HttpTransport(15, 15))(
                'POST',
                self::TOKEN_URL,
                ['Content-Type: application/x-www-form-urlencoded'],
                $body
            );
        } catch (\ApiGoat\Sync\Exceptions\TransientError $e) {
            // Original behaviour: a transport failure at the token endpoint is an auth failure.
            throw new AuthFailed('Token exchange cURL error: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $r['body'], true);
        if ((int) $r['status'] !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? "HTTP {$r['status']}";
            throw new AuthFailed('Token exchange failed' . ($subject ? " (sub={$subject})" : '') . ": {$msg}");
        }
        return [
            'access_token' => (string) $data['access_token'],
            'expires_in'   => (int) ($data['expires_in'] ?? 3600),
        ];
    }
}
