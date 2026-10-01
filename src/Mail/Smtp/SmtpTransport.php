<?php

namespace ApiGoat\Mail\Smtp;

interface SmtpTransport
{
    /**
     * Submit $mime to $recipients (the envelope: To + Cc + Bcc, bare
     * addresses). Returns only when the server answered 250 to the end of
     * DATA; any other outcome throws SmtpFailure. Never a partial send: one
     * refused recipient aborts the whole submission before DATA.
     *
     * @param string[] $recipients
     * @throws SmtpFailure
     */
    public function send(SmtpSettings $s, string $envelopeFrom, array $recipients, string $mime): void;
}
