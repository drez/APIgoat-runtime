<?php

namespace ApiGoat\Mail;

/**
 * RFC 822 text → {@see MailBody}. Uses zbateson/mail-mime-parser when the
 * project has it (a `suggest`, proven in apigoatacc); otherwise a small
 * built-in parser that handles the common shapes (single part, multipart/*
 * nested, base64 / quoted-printable, attachments by disposition/filename).
 */
final class MimeBodyParser
{
    public static function zbatesonAvailable(): bool
    {
        return class_exists(\ZBateson\MailMimeParser\Message::class);
    }

    public static function parse(string $raw, string $providerId = ''): MailBody
    {
        if (self::zbatesonAvailable()) {
            return self::viaZbateson($raw, $providerId);
        }
        return self::builtin($raw, $providerId);
    }

    private static function viaZbateson(string $raw, string $providerId): MailBody
    {
        $msg = \ZBateson\MailMimeParser\Message::from($raw, false);
        $attachments = [];
        foreach ($msg->getAllAttachmentParts() as $i => $part) {
            $attachments[] = [
                'filename' => (string) ($part->getFilename() ?? ''),
                'mime'     => strtolower((string) $part->getContentType()),
                'size'     => strlen((string) $part->getContent()),
                'part_id'  => (string) $i,
            ];
        }
        $headers = [];
        foreach ($msg->getAllHeaders() as $h) {
            $headers[strtolower($h->getName())] = (string) $h->getValue();
        }
        return new MailBody(
            $providerId,
            (string) $msg->getTextContent(),
            (string) $msg->getHtmlContent(),
            $attachments,
            $headers,
            strlen($raw),
        );
    }

    /** @internal exposed for tests */
    public static function builtin(string $raw, string $providerId = ''): MailBody
    {
        [$headers, $body] = self::splitHeaders($raw);
        $text = '';
        $html = '';
        $attachments = [];
        self::walk($headers, $body, $text, $html, $attachments, 0);
        return new MailBody($providerId, $text, $html, $attachments, $headers, strlen($raw));
    }

    /** @return array{0:array<string,string>,1:string} */
    private static function splitHeaders(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");
        $pos = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($raw);
        $head = substr($raw, 0, $pos);
        $body = substr($raw, $pos + strlen($m[0][0] ?? ''));
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head) ?? $head; // unfold
        $headers = [];
        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
        return [$headers, $body];
    }

    /** @param array<string,string> $headers */
    private static function walk(array $headers, string $body, string &$text, string &$html, array &$attachments, int $depth): void
    {
        $ctype = strtolower($headers['content-type'] ?? 'text/plain');
        $mime  = trim(explode(';', $ctype, 2)[0]);
        if (str_starts_with($mime, 'multipart/') && $depth < 10) {
            if (!preg_match('/boundary="?([^";]+)"?/i', $headers['content-type'] ?? '', $bm)) return;
            foreach (self::parts($body, $bm[1]) as $part) {
                [$ph, $pb] = self::splitHeaders($part);
                self::walk($ph, $pb, $text, $html, $attachments, $depth + 1);
            }
            return;
        }
        $disp     = strtolower($headers['content-disposition'] ?? '');
        $filename = self::filename($headers);
        $isAttach = str_starts_with($disp, 'attachment') || ($filename !== '' && !str_starts_with($mime, 'text/'));
        if ($isAttach || (!str_starts_with($mime, 'text/') && $mime !== '')) {
            if ($mime === 'text/plain' || $mime === 'text/html') {
                // inline text parts with a filename still count as content below
            }
            $attachments[] = [
                'filename' => $filename,
                'mime'     => $mime,
                'size'     => strlen(self::decode($body, $headers['content-transfer-encoding'] ?? '')),
            ];
            return;
        }
        $decoded = self::decode($body, $headers['content-transfer-encoding'] ?? '');
        $decoded = self::toUtf8($decoded, $headers['content-type'] ?? '');
        if ($mime === 'text/html') {
            if ($html === '') $html = $decoded;
        } elseif ($text === '') {
            $text = $decoded;
        }
    }

    /** @return string[] */
    private static function parts(string $body, string $boundary): array
    {
        $chunks = preg_split('/\r?\n?--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*(?:\r?\n|$)/', $body) ?: [];
        // First chunk is the preamble, last is the epilogue.
        array_shift($chunks);
        array_pop($chunks);
        return array_map(static fn ($c) => ltrim($c, "\r\n"), $chunks);
    }

    /** @param array<string,string> $headers */
    private static function filename(array $headers): string
    {
        foreach (['content-disposition', 'content-type'] as $h) {
            if (preg_match('/(?:file)?name\*?="?([^";]+)"?/i', $headers[$h] ?? '', $m)) {
                $n = $m[1];
                if (preg_match("/^[^']*'[^']*'(.*)$/", $n, $rfc)) $n = rawurldecode($rfc[1]);
                return HeaderRecord::decodeWords(trim($n));
            }
        }
        return '';
    }

    private static function decode(string $body, string $cte): string
    {
        return match (strtolower(trim($cte))) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    private static function toUtf8(string $s, string $contentType): string
    {
        if (preg_match('/charset="?([\w\-]+)"?/i', $contentType, $m)) {
            $cs = strtoupper($m[1]);
            if ($cs !== 'UTF-8' && $cs !== 'UTF8' && function_exists('mb_convert_encoding')) {
                $c = @mb_convert_encoding($s, 'UTF-8', $cs);
                if ($c !== false) return $c;
            }
        }
        return $s;
    }
}
