<?php

namespace ApiGoat\Mail\Smtp;

/**
 * Why a submission did not end in "250 OK" to the end of DATA.
 *
 *   connect   network, TLS, EHLO — nothing was sent (transient)
 *   auth      credentials; 5xx permanent, 4xx transient
 *   envelope  MAIL FROM / RCPT TO; 5xx permanent (bad recipient), 4xx transient
 *   data      the server ANSWERED the DATA phase with 4xx/5xx: not accepted
 *   uncertain the body was handed over and no final answer came back: the
 *             message MAY have been accepted. Never retried automatically.
 *             (The transport waits at least PhpMailerSmtpTransport::DATA_TIMEOUT
 *             — doubled by PHPMailer for the final reply — before concluding
 *             this, so a slow-but-successful server is not misread.)
 */
final class SmtpFailure extends \RuntimeException
{
    public const PHASE_CONNECT   = 'connect';
    public const PHASE_AUTH      = 'auth';
    public const PHASE_ENVELOPE  = 'envelope';
    public const PHASE_DATA      = 'data';
    public const PHASE_UNCERTAIN = 'uncertain';

    public function __construct(string $message, public readonly string $phase, public readonly int $smtpCode = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $smtpCode, $previous);
    }

    public function permanent(): bool
    {
        return $this->phase !== self::PHASE_UNCERTAIN && $this->smtpCode >= 500 && $this->smtpCode < 600;
    }

    public function mayHaveBeenSent(): bool
    {
        return $this->phase === self::PHASE_UNCERTAIN;
    }
}
