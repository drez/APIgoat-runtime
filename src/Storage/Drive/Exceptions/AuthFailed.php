<?php

namespace ApiGoat\Storage\Drive\Exceptions;

/**
 * Permanent auth failure: bad key, expired key, missing DWD grant,
 * scope not authorized, sub user not in domain.
 *
 * Do NOT retry — re-running with the same inputs will fail identically.
 */
class AuthFailed extends \RuntimeException
{
}
