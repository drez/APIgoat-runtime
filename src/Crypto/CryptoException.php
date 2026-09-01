<?php

namespace ApiGoat\Crypto;

/**
 * Anything SecretBox cannot do safely: no key, an unknown key version,
 * malformed input, or a MAC that does not verify. The message never carries
 * plaintext or key material.
 */
class CryptoException extends \RuntimeException
{
}
