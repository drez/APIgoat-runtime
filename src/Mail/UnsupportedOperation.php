<?php

namespace ApiGoat\Mail;

/** The connector cannot do this (yet) — phase-2/3 methods on a phase-1 connector, or a provider limit. Not retryable. */
final class UnsupportedOperation extends \LogicException
{
}
