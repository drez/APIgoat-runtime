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
        return password_verify((string) $clientSecret, $row->getClientSecretHash());
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
            if (!is_string($uri) || !filter_var($uri, FILTER_VALIDATE_URL)) {
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
            $secretHash = password_hash($secret, PASSWORD_DEFAULT);
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
}
