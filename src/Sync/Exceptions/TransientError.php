<?php

namespace ApiGoat\Sync\Exceptions;

/** Network/5xx — safe to retry. */
final class TransientError extends \RuntimeException
{
}
