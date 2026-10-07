<?php

namespace ApiGoat\Mail;

/**
 * RFC 822 text → the {@see BodyStructure} leaf list, and a section's STILL-ENCODED
 * bytes, for providers that hand over whole messages (Graph `$value`) instead of
 * an IMAP BODYSTRUCTURE. Sections follow RFC 3501 §6.4.5 (dotted, 1-based; a
 * non-multipart message is "1"); a message/rfc822 part is one leaf. Uses
 * zbateson/mail-mime-parser. Never throws on a malformed message: [] / null.
 */
final class MimeStructure
{
    /** Same cap as BodyStructure. */
    private const MAX_LEAVES = BodyStructure::MAX_LEAVES;
    private const MAX_DEPTH  = BodyStructure::MAX_DEPTH;

    /** @return list<array<string,mixed>> */
    public static function leaves(string $raw): array
    {
        try {
            $out = [];
            self::walk(self::root($raw), '', null, $out, 0);
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** The part's encoded content (no headers), or null when there is no such leaf. */
    public static function part(string $raw, string $section): ?string
    {
        if (!preg_match('/^\d+(\.\d+)*$/', $section)) {
            return null;
        }
        try {
            $p = self::root($raw);
            foreach (explode('.', $section) as $i => $n) {
                if ($p->isMultiPart()) {
                    $p = $p->getChild((int) $n - 1);
                } elseif ($i > 0 || (int) $n !== 1) {
                    return null;
                }
                if ($p === null) {
                    return null;
                }
            }
            if ($p->isMultiPart()) {
                return null;
            }
            return self::encodedBody($p);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function root(string $raw): \ZBateson\MailMimeParser\Message\IMimePart
    {
        return \ZBateson\MailMimeParser\Message::from($raw, false);
    }

    /** @param list<array<string,mixed>> $out */
    private static function walk($part, string $prefix, ?string $parent, array &$out, int $depth): void
    {
        if (count($out) >= self::MAX_LEAVES || $depth > self::MAX_DEPTH) {
            return;
        }
        if ($part->isMultiPart()) {
            $sub = strtolower(self::subtype($part));
            $i   = 0;
            foreach ($part->getChildParts() as $child) {
                $i++;
                self::walk($child, $prefix === '' ? (string) $i : $prefix . '.' . $i, $sub, $out, $depth + 1);
            }
            return;
        }
        [$type, $subtype] = array_pad(explode('/', strtolower((string) $part->getContentType('text/plain')), 2), 2, 'plain');
        $disp = $part->getContentDisposition();
        $id   = $part->getContentId();
        $desc = $part->getHeaderValue('Content-Description');
        $out[] = [
            'section'            => $prefix === '' ? '1' : $prefix,
            'type'               => $type,
            'subtype'            => $subtype,
            'params'             => self::params($part, 'Content-Type', ['boundary']),
            'id'                 => $id !== null && $id !== '' ? trim($id, '<>') : null,
            'description'        => $desc !== null && $desc !== '' ? (string) $desc : null,
            'encoding'           => strtolower((string) ($part->getHeaderValue('Content-Transfer-Encoding') ?? '7bit')) ?: '7bit',
            'size'               => strlen(self::encodedBody($part)),
            'disposition'        => $disp !== null && $disp !== '' ? strtolower($disp) : null,
            'disposition_params' => $disp !== null ? self::params($part, 'Content-Disposition', []) : [],
            'parent'             => $parent,
        ];
    }

    private static function subtype($part): string
    {
        $ct = strtolower((string) $part->getContentType('text/plain'));
        return str_contains($ct, '/') ? substr($ct, strpos($ct, '/') + 1) : $ct;
    }

    /** @param string[] $skip @return array<string,string> */
    private static function params($part, string $header, array $skip): array
    {
        $h = $part->getHeader($header);
        $out = [];
        if ($h !== null && method_exists($h, 'getParts')) {
            foreach ($h->getParts() as $p) {
                if ($p instanceof \ZBateson\MailMimeParser\Header\Part\ParameterPart) {
                    $k = strtolower($p->getName());
                    if (!in_array($k, $skip, true)) $out[$k] = (string) $p->getValue();
                }
            }
        }
        return $out;
    }

    /** The part's raw body: everything after its header block, still transfer-encoded. */
    private static function encodedBody($part): string
    {
        $s = (string) $part->getStream();
        if (!preg_match('/\r?\n\r?\n/', $s, $m, PREG_OFFSET_CAPTURE)) {
            return str_starts_with($s, "\r\n") || str_starts_with($s, "\n") ? ltrim($s, "\r\n") : '';
        }
        return substr($s, $m[0][1] + strlen($m[0][0]));
    }
}
