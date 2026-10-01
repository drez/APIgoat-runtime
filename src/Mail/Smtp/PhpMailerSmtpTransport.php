<?php

namespace ApiGoat\Mail\Smtp;

use PHPMailer\PHPMailer\SMTP;

/**
 * SmtpTransport over PHPMailer's low-level SMTP client, so the bytes sent
 * are exactly the MIME the caller built (MimeBuilder) — the same bytes a
 * Sent copy is made of. Peer verification is always on.
 */
class PhpMailerSmtpTransport implements SmtpTransport
{
    /** Minimum wait (s) for the DATA phase replies; the other phases use SmtpSettings::$timeout. */
    public const DATA_TIMEOUT = 300;

    public function send(SmtpSettings $s, string $envelopeFrom, array $recipients, string $mime): void
    {
        if ($recipients === []) {
            throw new \InvalidArgumentException('PhpMailerSmtpTransport: no recipient');
        }
        foreach (array_merge([$envelopeFrom], $recipients) as $a) {
            self::assertEnvelopeAddress((string) $a);
        }
        $smtp = $this->client();
        $smtp->do_debug  = SMTP::DEBUG_OFF;   // debug output would echo the AUTH exchange
        $smtp->Timeout   = $s->timeout;
        $smtp->Timelimit = $s->timeout;
        $host = ($s->security === SmtpSettings::TLS ? 'ssl://' : '') . $s->host;
        $tls  = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
        try {
            if (!$smtp->connect($host, $s->port, $s->timeout, $tls)) {
                throw self::fail($smtp, SmtpFailure::PHASE_CONNECT, "connect {$s->host}:{$s->port}");
            }
            if (!$smtp->hello(self::heloName())) {
                throw self::fail($smtp, SmtpFailure::PHASE_CONNECT, 'EHLO');
            }
            if ($s->security === SmtpSettings::STARTTLS) {
                if (!$smtp->startTLS()) {
                    throw self::fail($smtp, SmtpFailure::PHASE_CONNECT, 'STARTTLS');
                }
                if (!$smtp->hello(self::heloName())) {
                    throw self::fail($smtp, SmtpFailure::PHASE_CONNECT, 'EHLO after STARTTLS');
                }
            }
            if ($s->username !== '' && !$smtp->authenticate($s->username, $s->password())) {
                throw self::fail($smtp, SmtpFailure::PHASE_AUTH, 'AUTH');
            }
            if (!$smtp->mail($envelopeFrom)) {
                throw self::fail($smtp, SmtpFailure::PHASE_ENVELOPE, 'MAIL FROM');
            }
            // Every recipient is tried so the user sees every bad address at
            // once; one refusal still aborts the whole submission before DATA.
            $refused = [];
            $worst   = 0;
            foreach ($recipients as $r) {
                if (!$smtp->recipient($r)) {
                    $c         = self::code($smtp);
                    $refused[] = self::text($smtp, 'RCPT TO ' . $r);
                    $worst     = ($c >= 500 || $worst === 0) ? max($worst, $c) : $worst;
                }
            }
            if ($refused !== []) {
                $smtp->reset();
                throw new SmtpFailure(implode('; ', $refused), SmtpFailure::PHASE_ENVELOPE, $worst);
            }
            // RFC 5321 4.5.3.2.6: the reply to the final "." may take up to
            // 10 minutes (large message, server-side scanning). Giving up
            // earlier turns a delivered message into a false "may have been
            // sent". PHPMailer doubles Timelimit for DATA END.
            $smtp->Timeout   = max($s->timeout, self::DATA_TIMEOUT);
            $smtp->Timelimit = max($s->timeout, self::DATA_TIMEOUT);
            try {
                $ok = $smtp->data($mime);
            } catch (\Throwable $t) {
                // e.g. an error handler turning a stream warning into an
                // exception: the body may already be with the server.
                throw new SmtpFailure('SMTP DATA failed: ' . $t->getMessage() . ' — no final answer: the message may have been accepted', SmtpFailure::PHASE_UNCERTAIN, 0, $t);
            }
            if (!$ok) {
                $code = self::code($smtp);
                throw $code >= 400
                    ? new SmtpFailure(self::text($smtp, 'DATA'), SmtpFailure::PHASE_DATA, $code)
                    : new SmtpFailure(self::text($smtp, 'DATA') . ' — no final answer: the message may have been accepted', SmtpFailure::PHASE_UNCERTAIN, 0);
            }
        } finally {
            try {
                $smtp->quit();
            } catch (\Throwable) {
            }
            $smtp->close();
        }
    }

    /** Test seam. */
    protected function client(): SMTP
    {
        return new SMTP();
    }

    /**
     * MAIL FROM / RCPT TO interpolate the address into the command line: a
     * CR/LF, NUL, space or angle bracket would smuggle SMTP commands or
     * parameters. Refused before any connection (a caller bug, not an SMTP
     * outcome — never retried).
     */
    private static function assertEnvelopeAddress(string $a): void
    {
        if ($a === '' || preg_match('/[\x00-\x20\x7f<>]/', $a) || substr_count($a, '@') < 1) {
            throw new \InvalidArgumentException('PhpMailerSmtpTransport: invalid envelope address ' . json_encode($a));
        }
    }

    private static function fail(SMTP $smtp, string $phase, string $what): SmtpFailure
    {
        return new SmtpFailure(self::text($smtp, $what), $phase, self::code($smtp));
    }

    private static function code(SMTP $smtp): int
    {
        $e = $smtp->getError();
        $c = (int) ($e['smtp_code'] ?? 0);
        if ($c === 0 && preg_match('/^(\d{3})/', (string) $smtp->getLastReply(), $m)) {
            $c = (int) $m[1];
        }
        return $c;
    }

    private static function text(SMTP $smtp, string $what): string
    {
        $e = $smtp->getError();
        return trim(sprintf('SMTP %s failed: %s %s %s', $what, (string) ($e['smtp_code'] ?? ''), (string) ($e['error'] ?? ''), (string) ($e['detail'] ?? '')));
    }

    private static function heloName(): string
    {
        $h = gethostname();
        return is_string($h) && $h !== '' ? $h : 'localhost';
    }
}
