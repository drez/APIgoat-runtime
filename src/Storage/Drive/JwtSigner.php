<?php

namespace ApiGoat\Storage\Drive;

use ApiGoat\Storage\Drive\Exceptions\AuthFailed;
use Firebase\JWT\JWT;

/**
 * Mint short-lived Google OAuth access tokens from a service account key
 * using JWT-bearer grant, optionally impersonating a Workspace user via
 * Domain-Wide Delegation (the `sub` claim).
 */
class JwtSigner
{
    private string $serviceEmail;
    private string $privateKey;

    public function __construct(string $serviceEmail, string $privateKey)
    {
        $this->serviceEmail = $serviceEmail;
        $this->privateKey   = $privateKey;
    }

    public static function fromKeyFile(string $serviceEmail, string $keyPath): self
    {
        if (!file_exists($keyPath)) {
            throw new AuthFailed("Service account key file not found: {$keyPath}");
        }
        $keyData = json_decode((string) file_get_contents($keyPath), true);
        if (empty($keyData['private_key'])) {
            throw new AuthFailed("Invalid service account key file: missing private_key");
        }
        return new self($serviceEmail, $keyData['private_key']);
    }

    /**
     * Exchange a JWT assertion for a Google OAuth access token.
     *
     * @param string[]    $scopes  Google API OAuth scopes
     * @param string|null $subject Workspace user to impersonate via DWD,
     *                             or null to act as the service account itself
     * @return array{access_token: string, expires_in: int}
     */
    public function mintAccessToken(array $scopes, ?string $subject = null): array
    {
        $now = time();
        $payload = [
            'iss'   => $this->serviceEmail,
            'scope' => implode(' ', $scopes),
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        if ($subject !== null && $subject !== '') {
            $payload['sub'] = $subject;
        }

        $jwt = JWT::encode($payload, $this->privateKey, 'RS256');

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new AuthFailed('Token exchange cURL error: ' . $curlErr);
        }

        $data = json_decode((string) $result, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? "HTTP {$httpCode}";
            throw new AuthFailed(
                'Token exchange failed' . ($subject ? " (sub={$subject})" : '') . ": {$msg}"
            );
        }

        return [
            'access_token' => $data['access_token'],
            'expires_in'   => (int) ($data['expires_in'] ?? 3600),
        ];
    }
}
