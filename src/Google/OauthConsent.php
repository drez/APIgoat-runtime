<?php

namespace ApiGoat\Google;

use ApiGoat\Mail\Token\OauthTokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * The consent half of per-user OAuth — the one thing
 * {@see OauthTokenSource} cannot do for itself: obtain the refresh token in
 * the first place.
 *
 *   1. send the user to authUrl(...) (a 302 from a session-authenticated route)
 *   2. Google redirects back to $redirectUri with ?code=…&state=…
 *   3. exchange(...) turns that code into a REFRESH token, stored once
 *
 * Two parameters carry the whole flow and both are easy to lose:
 * `access_type=offline` is what makes Google issue a refresh token at all,
 * and `prompt=consent` is what makes it issue one AGAIN for an account that
 * already granted this client — without it a re-connect silently returns an
 * access token only, and the stored grant would expire in an hour. When the
 * refresh token is missing anyway, exchange() throws with the only recovery
 * that works: revoke the app at myaccount.google.com and consent again.
 *
 * Stateless by design: the caller owns the `state` (a signed, single-use
 * token) and the storage. The transport is injectable, so the whole flow is
 * testable without ever reaching Google.
 */
final class OauthConsent
{
    public const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL    = OauthTokenSource::TOKEN_URL;
    public const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /** Scopes that make userinfo answer with an address. */
    public const IDENTITY_SCOPES = ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/userinfo.email'];

    /**
     * Where to send the browser. `state` is opaque here — sign it and make it
     * single-use in the caller; this class only url-encodes it.
     *
     * @param string[] $scopes
     */
    public static function authUrl(string $clientId, string $redirectUri, array $scopes, string $state): string
    {
        if (trim($clientId) === '' || trim($redirectUri) === '') {
            throw new AuthFailed('OauthConsent::authUrl needs a client_id and a redirect_uri');
        }
        if ($scopes === []) {
            throw new AuthFailed('OauthConsent::authUrl needs at least one scope');
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => $clientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => implode(' ', $scopes),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /**
     * Authorization code → refresh token (+ the first access token).
     *
     * `email` is the Google account that consented, read from userinfo when
     * the granted scope carries an identity. The caller compares it with the
     * mailbox it is connecting: a user who signs in with the wrong account is
     * the failure mode this flow has, and the address is how you catch it. A
     * userinfo failure leaves it null rather than losing a grant that already
     * succeeded.
     *
     * @return array{refresh_token:string, access_token:string, expires_in:int, scope:string, email:?string}
     */
    public static function exchange(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $code,
        ?callable $transport = null
    ): array {
        if (trim($clientId) === '' || trim($clientSecret) === '' || trim($code) === '') {
            throw new AuthFailed('OauthConsent::exchange needs a client_id, a client_secret and a code');
        }
        $http = $transport ?? new HttpTransport(20, 15);

        $r = $http('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
        ]));

        $status = (int) $r['status'];
        $data   = json_decode((string) $r['body'], true);
        $data   = is_array($data) ? $data : [];

        if ($status >= 500) {
            throw new TransientError('Google token endpoint HTTP ' . $status, $status);
        }
        if ($status !== 200 || empty($data['access_token'])) {
            $msg = $data['error_description'] ?? $data['error'] ?? "HTTP {$status}";
            throw new AuthFailed('OAuth code exchange failed: ' . $msg, $status);
        }

        $refresh = (string) ($data['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new AuthFailed(
                'Google returned no refresh token for this consent. That happens when this Google account '
                . 'already granted this application: open https://myaccount.google.com/permissions, revoke '
                . 'this app\'s access, then run the connect flow again.'
            );
        }

        $scope = (string) ($data['scope'] ?? '');

        return [
            'refresh_token' => $refresh,
            'access_token'  => (string) $data['access_token'],
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
            'scope'         => $scope,
            'email'         => self::identity($http, (string) $data['access_token'], $scope),
        ];
    }

    /** true when the granted scope string can answer userinfo with an address. */
    public static function grantsIdentity(string $scope): bool
    {
        foreach (preg_split('/\s+/', trim($scope)) ?: [] as $s) {
            if ($s !== '' && in_array($s, self::IDENTITY_SCOPES, true)) {
                return true;
            }
        }
        return false;
    }

    /** The consenting account's address, lower-cased; null when unavailable. */
    private static function identity(callable $http, string $accessToken, string $scope): ?string
    {
        if (!self::grantsIdentity($scope)) {
            return null;
        }
        try {
            $r = $http('GET', self::USERINFO_URL, ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'], null);
            if ((int) $r['status'] < 200 || (int) $r['status'] >= 300) {
                return null;
            }
            $u     = json_decode((string) $r['body'], true);
            $email = is_array($u) ? strtolower(trim((string) ($u['email'] ?? ''))) : '';
            return $email !== '' ? $email : null;
        } catch (\Throwable $e) {
            // The grant is already ours; not knowing who consented is a weaker
            // check, not a reason to throw the refresh token away.
            return null;
        }
    }
}
