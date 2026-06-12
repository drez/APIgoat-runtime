<?php

namespace ApiGoat\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Verifies Google Identity Services ID tokens (GIS "Sign in with Google")
 * against Google's published JWKS. No OAuth code flow, no token storage.
 * Fail-closed: any problem throws RuntimeException with a user-safe message.
 */
class GoogleIdToken
{
    const JWKS_URL  = 'https://www.googleapis.com/oauth2/v3/certs';
    const ISSUERS   = ['https://accounts.google.com', 'accounts.google.com'];
    const CACHE_TTL = 3600;

    private $cacheFile;

    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile ?: sys_get_temp_dir() . '/gc-google-jwks.json';
    }

    /**
     * @return array verified claims (sub, email, name, picture, ...)
     * @throws \RuntimeException user-safe message; token contents are never logged
     */
    public function verify(string $idToken, string $audience): array
    {
        if ($audience === '' || $idToken === '') {
            throw new \RuntimeException(_('Google sign-in is not configured'));
        }
        $keys = $this->keys();
        $prevLeeway = JWT::$leeway;
        JWT::$leeway = 60;
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($keys, 'RS256'));
        } catch (\Exception $e) {
            throw new \RuntimeException(_('Google sign-in failed'));
        } finally {
            JWT::$leeway = $prevLeeway;
        }
        $emailVerified = $claims['email_verified'] ?? false;
        if (!in_array($claims['iss'] ?? '', self::ISSUERS, true)
            || ($claims['aud'] ?? '') !== $audience
            || empty($claims['sub'])
            || empty($claims['email'])
            || ($emailVerified !== true && $emailVerified !== 'true')
        ) {
            throw new \RuntimeException(_('Google sign-in failed'));
        }
        return $claims;
    }

    private function keys(): array
    {
        $f = $this->cacheFile;
        if (is_file($f) && (time() - filemtime($f)) < self::CACHE_TTL) {
            $cached = json_decode((string) file_get_contents($f), true);
            if (!empty($cached['keys'])) {
                return $cached;
            }
        }
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw  = @file_get_contents(self::JWKS_URL, false, $ctx);
        $json = $raw ? json_decode($raw, true) : null;
        if (empty($json['keys'])) {
            // Stale cache beats hard-down, but never beats nothing.
            if (is_file($f)) {
                $stale = json_decode((string) file_get_contents($f), true);
                if (!empty($stale['keys'])) {
                    return $stale;
                }
            }
            throw new \RuntimeException(_('Google sign-in unavailable'));
        }
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $raw) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $f);
        }
        return $json;
    }
}
