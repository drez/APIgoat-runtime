<?php

namespace ApiGoat\Mail;

/**
 * A MIME part is larger than the caller's cap. Thrown while streaming, as
 * soon as the decoded byte count passes the cap (never after reading it all).
 */
final class PartTooLarge extends \RuntimeException
{
    public function __construct(public readonly int $limit, string $message = '')
    {
        parent::__construct($message !== '' ? $message : sprintf('The part is larger than %d bytes.', $limit));
    }
}
