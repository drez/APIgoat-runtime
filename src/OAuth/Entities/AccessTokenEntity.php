<?php

namespace ApiGoat\OAuth\Entities;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait;   // convertToJWT() + JWT signing
    use TokenEntityTrait;   // identifier/expiry/client/scopes/userIdentifier
    use EntityTrait;
}
