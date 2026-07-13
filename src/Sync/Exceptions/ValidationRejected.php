<?php

namespace ApiGoat\Sync\Exceptions;

/** Provider rejected the data — retrying identical input cannot succeed; job goes straight to Failed. */
final class ValidationRejected extends \RuntimeException
{
}
