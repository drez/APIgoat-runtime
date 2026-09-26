<?php
namespace ApiGoat\OAuth;

use ApiGoat\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $row = \App\OauthClientQuery::create()->filterByClientId((string) $clientIdentifier)->findOne();
        if (!$row) {
            return null;
        }
        $c = new ClientEntity();
        $c->setIdentifier($row->getClientId());
        $c->setName((string) $row->getName());
        $c->setRedirectUri(json_decode((string) $row->getRedirectUris(), true) ?: []);
        $c->setConfidential($row->getIsConfidential() === 'Yes');
        $c->setRegisteredScopes(method_exists($row, 'getScopes') ? $row->getScopes() : null);
        return $c;
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $row = \App\OauthClientQuery::create()->filterByClientId((string) $clientIdentifier)->findOne();
        if (!$row) {
            return false;
        }
        // public PKCE client: no secret stored, none required
        if ($row->getIsConfidential() !== 'Yes') {
            return true;
        }
        // confidential: verify the secret
        if ($clientSecret === null || $row->getClientSecretHash() === null) {
            return false;
        }
        $check = self::verifySecret((string) $clientSecret, (string) $row->getClientSecretHash());
        if ($check['ok'] && $check['rehash'] !== null) {
            try {
                $row->setClientSecretHash($check['rehash']);
                $row->save();
            } catch (\Throwable $e) {
                // upgrade is an optimisation: the secret was valid either way
                error_log('[oauth] client secret rehash failed: ' . $e->getMessage());
            }
        }
        return $check['ok'];
    }

    /** Stored form of a server-minted client secret (see verifySecret()). */
    public static function hashSecret(string $secret): string
    {
        return 'sha256$' . hash('sha256', $secret);
    }

    /**
     * Verify a client secret against its stored hash.
     *
     * Client secrets are server-minted (bin2hex(random_bytes(32)) in
     * register()), 256 random bits — never a human password — so a slow KDF
     * adds no protection (nobody can brute-force 2^256), while bcrypt cost
     * 12 (PHP 8.4's default) spent ~280 ms of every token call. They are
     * stored as sha256$<hex> and compared in constant time. Legacy bcrypt
     * rows still verify; a correct secret yields a `rehash` to the fast
     * form, a wrong one never does. Anything else fails closed.
     *
     * @return array{ok: bool, rehash: ?string}
     */
    public static function verifySecret(string $secret, string $stored): array
    {
        if ($secret === '') {
            return ['ok' => false, 'rehash' => null];
        }
        if (str_starts_with($stored, 'sha256$') && strlen($stored) === 71) {
            return ['ok' => hash_equals($stored, self::hashSecret($secret)), 'rehash' => null];
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2b$')) {
            $ok = password_verify($secret, $stored);
            return ['ok' => $ok, 'rehash' => $ok ? self::hashSecret($secret) : null];
        }
        return ['ok' => false, 'rehash' => null];
    }

    /**
     * RFC 7591 Dynamic Client Registration. Returns the RFC 7591 response array.
     * @throws \InvalidArgumentException on invalid metadata (controller maps to 400 invalid_client_metadata)
     */
    public function register(array $meta): array
    {
        $redirects = $meta['redirect_uris'] ?? null;
        if (!is_array($redirects) || $redirects === []) {
            throw new \InvalidArgumentException('redirect_uris required');
        }
        foreach ($redirects as $uri) {
            if (!is_string($uri) || !self::isValidRedirectUri($uri)) {
                throw new \InvalidArgumentException('invalid redirect_uri');
            }
        }
        $grants = $meta['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grants)) {
            throw new \InvalidArgumentException('grant_types must be an array');
        }
        $allowed = ['authorization_code', 'refresh_token'];
        if (array_diff($grants, $allowed) !== []) {
            throw new \InvalidArgumentException('unsupported grant_types');
        }

        $clientId = bin2hex(random_bytes(16));
        $isConfidential = (($meta['token_endpoint_auth_method'] ?? 'none') !== 'none');
        $secret = null;
        $secretHash = null;
        if ($isConfidential) {
            $secret = bin2hex(random_bytes(32));
            $secretHash = self::hashSecret($secret);
        }

        $row = new \App\OauthClient();
        $row->setClientId($clientId);
        $row->setClientSecretHash($secretHash);
        $row->setName((string) ($meta['client_name'] ?? 'MCP client'));
        $row->setRedirectUris(json_encode(array_values($redirects)));
        $row->setGrantTypes(implode(' ', $grants));
        $row->setScopes((string) ($meta['scope'] ?? 'crm:read crm:write offline_access'));
        $row->setIsConfidential($isConfidential ? 'Yes' : 'No');
        $row->setCreatedAt(new \DateTime());
        $row->save();

        $resp = [
            'client_id'                  => $clientId,
            'client_name'                => $row->getName(),
            'redirect_uris'              => array_values($redirects),
            'grant_types'                => $grants,
            'token_endpoint_auth_method' => $isConfidential ? 'client_secret_basic' : 'none',
        ];
        if ($secret !== null) {
            $resp['client_secret'] = $secret;
        }
        return $resp;
    }

    /**
     * OAuth redirect URIs must be absolute https URLs with no fragment.
     * http is permitted only for loopback hosts (native/dev clients).
     * (FILTER_VALIDATE_URL alone accepts plaintext http and fragment-bearing
     * URIs, which are unsafe redirect targets for token delivery.)
     */
    private static function isValidRedirectUri(string $uri): bool
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
