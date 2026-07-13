<?php

namespace ApiGoat\Sync\Exceptions;

/** Provider throttled us — retry later. */
final class RateLimited extends \RuntimeException
{
}
