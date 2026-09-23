<?php

namespace ApiGoat\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    // ClientTrait provides protected $name/$redirectUri/$isConfidential + getters.
    public function setName(string $name): void { $this->name = $name; }
    public function setRedirectUri($uri): void { $this->redirectUri = $uri; }
    public function setConfidential(bool $isConfidential): void { $this->isConfidential = $isConfidential; }

    /** @var list<string>|null oauth_client.scopes (space-separated at rest); null = not loaded */
    private ?array $registeredScopes = null;

    public function setRegisteredScopes(?string $scopes): void
    {
        $this->registeredScopes = $scopes === null
            ? null
            : array_values(array_filter(preg_split('/\s+/', trim($scopes)) ?: [], 'strlen'));
    }

    /** @return list<string>|null */
    public function getRegisteredScopes(): ?array { return $this->registeredScopes; }
}
