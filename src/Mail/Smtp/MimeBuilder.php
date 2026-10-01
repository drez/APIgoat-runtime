<?php

namespace ApiGoat\Mail\Smtp;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * OutgoingMessage → raw RFC 822 (CRLF), built by PHPMailer but never sent by
 * it: the same bytes go to SmtpTransport and to a Sent / Drafts APPEND.
 * Mailer = smtp, so Bcc never appears in the headers of a sent message; a
 * DRAFT copy asks for it ($bccHeader) so another client editing the draft
 * still sees it. X-Mailer is suppressed.
 *
 * Header safety: addresses go through PHPMailer's validation (an invalid or
 * CR/LF-carrying address is refused), display names and the subject have
 * CR/LF stripped by PHPMailer, and the ids (Message-ID, In-Reply-To,
 * References) must be well-formed msg-ids or the build is refused. Any
 * PHPMailer refusal surfaces as \InvalidArgumentException.
 *
 * Folding: PHPMailer writes References and address lists on one physical
 * line and Q-encodes a custom header longer than ~980 chars — an encoded
 * References no longer threads. So References (and a draft's Bcc) are
 * written here, folded between ids, and To / Cc / Bcc are folded between
 * addresses, keeping every line under the RFC 5322 998-char limit.
 */
final class MimeBuilder
{
    /** Envelope-only stand-in for a draft with no recipient yet: PHPMailer refuses zero recipients; Mailer=smtp keeps a Bcc out of the MIME. */
    private const NO_RECIPIENT = 'undisclosed@draft.apigmail.invalid';

    /** Printable US-ASCII except '<' and '>' (no space, no CTL, no 8-bit). */
    private const ID_CHARS = '[\x21-\x3B\x3D\x3F-\x7E]';

    /** One msg-id: angle brackets around ID_CHARS. */
    private const MSG_ID = '<' . self::ID_CHARS . '+>';

    /** RFC 2045 type/subtype token grammar; anything else becomes application/octet-stream. */
    private const MIME_TYPE = '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~i';

    /** Display names are cut to this many characters (cosmetic; keeps header lines legal). */
    private const NAME_MAX = 256;

    /** Longest single id accepted (a folded line holds one id plus a space). */
    private const MSG_ID_MAX = 900;

    public static function build(OutgoingMessage $m, bool $bccHeader = false, bool $allowNoRecipient = false): string
    {
        if (!preg_match('/^<[\x21-\x3B\x3D\x3F\x41-\x7E]+@[\x21-\x3B\x3D\x3F\x41-\x7E]+>$/', $m->messageId)) {
            throw new \InvalidArgumentException('MimeBuilder: Message-ID must look like <local@domain>, got ' . json_encode($m->messageId));
        }
        $inReplyTo  = self::inReplyTo($m->inReplyTo);
        $references = self::references($m->references);
        if ($m->envelope() === [] && !$allowNoRecipient) {
            throw new \InvalidArgumentException('MimeBuilder: no recipient');
        }
        foreach ($m->attachments as $att) {
            $cid = (string) ($att['content_id'] ?? '');
            if ($cid !== '' && !preg_match('/^' . self::ID_CHARS . '+$/', $cid)) {
                throw new \InvalidArgumentException('MimeBuilder: invalid Content-ID ' . json_encode($cid));
            }
        }
        try {
            $p = new PHPMailer(true);
            $p->isSMTP();
            $p->CharSet  = PHPMailer::CHARSET_UTF8;
            $p->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $p->XMailer  = ' ';
            $p->AllowEmpty = true;   // a blank draft is still a draft
            $p->setFrom($m->fromAddr, self::name($m->fromName), false);
            foreach ($m->to as $a) {
                $p->addAddress((string) $a['addr'], self::name($a['name'] ?? ''));
            }
            foreach ($m->cc as $a) {
                $p->addCC((string) $a['addr'], self::name($a['name'] ?? ''));
            }
            foreach ($m->bcc as $a) {
                $p->addBCC((string) $a['addr'], self::name($a['name'] ?? ''));
            }
            if ($m->envelope() === []) {
                $p->addBCC(self::NO_RECIPIENT);
            }
            $p->Subject     = $m->subject;
            $p->MessageID   = $m->messageId;
            $p->MessageDate = ($m->date ?? new \DateTimeImmutable())->format('D, d M Y H:i:s O');
            if ($inReplyTo !== null) {
                $p->addCustomHeader('In-Reply-To', $inReplyTo);
            }
            // Bodies are CRLF-canonical before encoding: QP would turn a bare
            // LF / CR into =0A / =0D (RFC 2045 6.7).
            $text = PHPMailer::normalizeBreaks($m->text, "\r\n");
            if ($m->html !== null && trim($m->html) !== '') {
                $p->isHTML(true);
                $p->Body = PHPMailer::normalizeBreaks($m->html, "\r\n");
                if (trim($text) === '') {
                    $text = PHPMailer::normalizeBreaks($p->html2text($m->html), "\r\n");
                }
                // The text part is always present: PHPMailer drops the
                // alternative when AltBody is empty.
                $p->AltBody = trim($text) === '' ? ' ' : $text;
            } else {
                $p->isHTML(false);
                $p->Body = $text;
            }
            foreach ($m->attachments as $att) {
                $cid  = (string) ($att['content_id'] ?? '');
                $mime = preg_match(self::MIME_TYPE, (string) ($att['mime'] ?? '')) ? (string) $att['mime'] : 'application/octet-stream';
                if ($cid !== '') {
                    $p->addStringEmbeddedImage($att['content'], $cid, $att['filename'], PHPMailer::ENCODING_BASE64, $mime, 'inline');
                } else {
                    $p->addStringAttachment($att['content'], $att['filename'], PHPMailer::ENCODING_BASE64, $mime);
                }
            }
            $p->preSend();
        } catch (PhpMailerException $e) {
            throw new \InvalidArgumentException('MimeBuilder: ' . $e->getMessage(), 0, $e);
        }
        if ($p->getLastMessageID() !== $m->messageId) {
            throw new \InvalidArgumentException('MimeBuilder: PHPMailer rejected the Message-ID ' . $m->messageId);
        }

        $extra = [];
        if ($references !== null) {
            $extra[] = self::fold('References: ', $references, ' ');
        }
        if ($bccHeader) {
            $bcc = [];
            foreach ($p->getBccAddresses() as $a) {
                if ($a[0] !== self::NO_RECIPIENT) {
                    $bcc[] = $p->addrFormat($a);
                }
            }
            if ($bcc !== []) {
                $extra[] = self::fold('Bcc: ', $bcc, ',');
            }
        }
        return self::finish($p->getSentMIMEMessage(), $extra, $p->secureHeader($m->subject));
    }

    private static function name(mixed $v): string
    {
        $v = (string) $v;
        return mb_strlen($v, 'UTF-8') > self::NAME_MAX ? mb_substr($v, 0, self::NAME_MAX, 'UTF-8') : $v;
    }

    /**
     * Our own RFC 2047 encoding for a subject PHPMailer would leave on an
     * over-long line: B encoded-words of at most 45 bytes (whole UTF-8
     * characters), one per folded line. Decoders join adjacent words.
     */
    private static function encodeSubject(string $subject): string
    {
        $words = [];
        $cur   = '';
        foreach (mb_str_split($subject, 1, 'UTF-8') as $ch) {
            if ($cur !== '' && strlen($cur) + strlen($ch) > 45) {
                $words[] = '=?utf-8?B?' . base64_encode($cur) . '?=';
                $cur     = '';
            }
            $cur .= $ch;
        }
        if ($cur !== '') {
            $words[] = '=?utf-8?B?' . base64_encode($cur) . '?=';
        }
        return 'Subject: ' . implode("\r\n ", $words);
    }

    private static function inReplyTo(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = trim($v);
        if (strlen($v) > self::MSG_ID_MAX || !preg_match('/^' . self::MSG_ID . '$/', $v)) {
            throw new \InvalidArgumentException('MimeBuilder: In-Reply-To must be one <msg-id>, got ' . json_encode($v));
        }
        return $v;
    }

    /** @return string[]|null the ids, in order */
    private static function references(?string $v): ?array
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        if (!preg_match('/^(?:[ \t]*' . self::MSG_ID . ')+[ \t]*$/', $v)) {
            throw new \InvalidArgumentException('MimeBuilder: References must be <msg-id>s separated by spaces, got ' . json_encode($v));
        }
        preg_match_all('/' . self::MSG_ID . '/', $v, $m);
        foreach ($m[0] as $id) {
            if (strlen($id) > self::MSG_ID_MAX) {
                throw new \InvalidArgumentException('MimeBuilder: a References id is too long');
            }
        }
        return $m[0];
    }

    /**
     * "Name: a b c" folded so each physical line stays near 78 chars: items
     * joined by $sep, a CRLF + space before an item that would overflow.
     *
     * @param string[] $items
     */
    private static function fold(string $name, array $items, string $sep): string
    {
        $out  = $name;
        $line = strlen($name);
        foreach (array_values($items) as $i => $item) {
            $parts = explode("\r\n", $item);
            if ($i > 0) {
                if ($sep !== ' ') {
                    $out .= $sep;
                    $line += strlen($sep);
                }
                if ($line + 1 + strlen($parts[0]) > 78) {
                    $out .= "\r\n ";
                    $line = 1;
                } else {
                    $out .= ' ';
                    $line++;
                }
            }
            $out .= $item;
            $line = count($parts) > 1 ? strlen(end($parts)) : $line + strlen($item);
        }
        return $out;
    }

    /**
     * Fold the To / Cc lines PHPMailer wrote on one line and insert the extra
     * headers right after Message-ID / In-Reply-To.
     *
     * @param string[] $extra complete, folded header fields (no trailing CRLF)
     */
    private static function finish(string $raw, array $extra, string $subject): string
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        // Group physical lines into logical fields (a continuation starts with SP / HTAB).
        $fields = [];
        foreach (explode("\r\n", $head) as $line) {
            if ($fields !== [] && ($line[0] ?? '') !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $fields[count($fields) - 1] .= "\r\n" . $line;
            } else {
                $fields[] = $line;
            }
        }
        $out    = [];
        $anchor = null;
        foreach ($fields as $f) {
            if (preg_match('/^(To|Cc): (.*)$/s', $f, $mm) && $mm[2] !== 'undisclosed-recipients:;') {
                $f = self::fold($mm[1] . ': ', self::splitAddresses($mm[2]), ',');
            } elseif (str_starts_with($f, 'Subject: ') && max(array_map('strlen', explode("\r\n", $f))) > 998) {
                $f = self::encodeSubject($subject);
            }
            $out[] = $f;
            if (preg_match('/^(Message-ID|In-Reply-To):/i', $f)) {
                $anchor = count($out);
            }
        }
        array_splice($out, $anchor ?? count($out), 0, $extra);
        return implode("\r\n", $out) . "\r\n\r\n" . $body;
    }

    /**
     * Split a PHPMailer address list ("a@x, \"Doe, J\" <j@y>") at the commas
     * outside quoted strings.
     *
     * @return string[]
     */
    private static function splitAddresses(string $v): array
    {
        $items = [];
        $cur   = '';
        $quote = false;
        $len   = strlen($v);
        for ($i = 0; $i < $len; $i++) {
            $c = $v[$i];
            if ($c === '\\' && $quote && $i + 1 < $len) {
                $cur .= $c . $v[++$i];
                continue;
            }
            if ($c === '"') {
                $quote = !$quote;
            }
            if ($c === ',' && !$quote) {
                $items[] = trim($cur, ' ');
                $cur     = '';
                continue;
            }
            $cur .= $c;
        }
        $items[] = trim($cur, ' ');
        return array_values(array_filter($items, static fn (string $s): bool => $s !== ''));
    }
}
