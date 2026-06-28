<?php

namespace ApiGoat\OAuth\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

class UserEntity implements UserEntityInterface
{
    public function __construct(private string $identifier) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
