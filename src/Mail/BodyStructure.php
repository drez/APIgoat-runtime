<?php

namespace ApiGoat\Mail;

/**
 * An IMAP BODYSTRUCTURE (RFC 3501 §7.4.2), parsed into the message's MIME
 * LEAVES — what a client lists as parts and downloads one by one with
 * BODY.PEEK[<section>], never the whole message.
 *
 * Pure: input is the raw FETCH response text (literals kept as
 * "{n}\r\n<n bytes>", as the server sent them), output is plain arrays.
 * Section numbers follow RFC 3501 §6.4.5: the parts of a multipart are
 * 1..n (nested "1.2"), and a message that is not multipart has one part, "1".
 * A message/rfc822 part (a forwarded email) is a leaf: as an attachment it
 * is the whole .eml, never its inner parts.
 *
 * Leaf shape:
 *   section:string, type:string, subtype:string (both lower case),
 *   params:array<string,string> (lower-case keys, RFC 2231 / 2047 decoded),
 *   id:?string (Content-ID without <>), description:?string,
 *   encoding:string (lower case, '7bit' when the server gave none),
 *   size:int (ENCODED octets, as the server counts them),
 *   disposition:?string (lower case), disposition_params:array<string,string>,
 *   parent:?string (the enclosing multipart's subtype, lower case; null at top level)
 *
 * Anything that does not parse is an \InvalidArgumentException — a caller
 * never gets a half structure.
 */
final class BodyStructure
{
    /** Leaves past this are dropped (a hostile message with thousands of parts). */
    public const MAX_LEAVES = 500;
    /** Nesting deeper than this is refused. */
    private const MAX_DEPTH = 40;

    /**
     * @param string $fetch the untagged FETCH response (or just the parenthesised BODYSTRUCTURE)
     * @return list<array<string,mixed>>
     */
    public static function parse(string $fetch): array
    {
        $pos = stripos($fetch, 'BODYSTRUCTURE');
        $src = $pos === false ? $fetch : substr($fetch, $pos + strlen('BODYSTRUCTURE'));
        $i   = 0;
        self::skipSpace($src, $i);
        if (($src[$i] ?? '') !== '(') {
            throw new \InvalidArgumentException('BODYSTRUCTURE: no structure in the response');
        }
        $tree   = self::readList($src, $i, 0);
        $leaves = [];
        self::walk($tree, '', null, $leaves, 0);
        return $leaves;
    }

    /** RFC 2231 continuations / charsets and RFC 2047 encoded words → one UTF-8 value per lower-case key. */
    public static function decodeParams(?array $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $plain = [];
        $parts = [];   // name => [index => [value, encoded]]
        for ($k = 0; $k + 1 < count($list); $k += 2) {
            if (!is_string($list[$k]) || !is_string($list[$k + 1])) {
                continue;
            }
            $key = strtolower(trim($list[$k]));
            $val = $list[$k + 1];
            if (preg_match('/^([^*]+)\*(?:(\d{1,3})(\*)?)?$/', $key, $m)) {
                // name* (one encoded value) or name*N / name*N* (continuations)
                $idx = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
                $enc = !isset($m[2]) || $m[2] === '' || isset($m[3]);
                $parts[$m[1]][$idx] = [$val, $enc];
                continue;
            }
            $plain[$key] = $val;
        }
        $out = [];
        foreach ($plain as $k => $v) {
            $out[$k] = self::decodeWords($v);
        }
        foreach ($parts as $name => $segs) {
            ksort($segs);
            $charset = 'UTF-8';
            $value   = '';
            $first   = true;
            foreach ($segs as [$v, $enc]) {
                if ($first && $enc && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                    $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                    $v       = $m[2];
                }
                $value .= $enc ? rawurldecode($v) : $v;
                $first = false;
            }
            $out[$name] = self::toUtf8($value, $charset);   // the 2231 form wins over a plain one
        }
        return $out;
    }

    /** @param array<int,mixed> $node */
    private static function walk(array $node, string $section, ?string $parent, array &$leaves, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('BODYSTRUCTURE: nested too deep');
        }
        if (isset($node[0]) && is_array($node[0])) {
            // multipart: (child)(child)… "SUBTYPE" [params dsp lang loc]
            $n = 0;
            while (isset($node[$n]) && is_array($node[$n])) {
                $n++;
            }
            $subtype = is_string($node[$n] ?? null) ? strtolower($node[$n]) : 'mixed';
            for ($c = 0; $c < $n; $c++) {
                self::walk($node[$c], $section === '' ? (string) ($c + 1) : $section . '.' . ($c + 1), $subtype, $leaves, $depth + 1);
            }
            return;
        }
        if (count($leaves) >= self::MAX_LEAVES) {
            return;
        }
        $type    = strtolower(self::str($node[0] ?? null) ?? 'application');
        $subtype = strtolower(self::str($node[1] ?? null) ?? 'octet-stream');
        $dspAt   = 8;
        if ($type === 'text') {
            $dspAt = 9;                       // + lines, md5
        } elseif ($type === 'message' && in_array($subtype, ['rfc822', 'global'], true)) {
            $dspAt = 11;                      // + envelope, body, lines, md5
        }
        $dsp = $node[$dspAt] ?? null;
        $id  = self::str($node[3] ?? null);
        $leaves[] = [
            'section'            => $section === '' ? '1' : $section,
            'type'               => $type,
            'subtype'            => $subtype,
            'params'             => self::decodeParams(is_array($node[2] ?? null) ? $node[2] : null),
            'id'                 => $id === null ? null : (trim($id, " \t<>") !== '' ? trim($id, " \t<>") : null),
            'description'        => ($d = self::str($node[4] ?? null)) === null ? null : self::decodeWords($d),
            'encoding'           => strtolower(self::str($node[5] ?? null) ?? '7bit'),
            'size'               => max(0, (int) (self::str($node[6] ?? null) ?? 0)),
            'disposition'        => is_array($dsp) && is_string($dsp[0] ?? null) ? strtolower($dsp[0]) : null,
            'disposition_params' => is_array($dsp) ? self::decodeParams(is_array($dsp[1] ?? null) ? $dsp[1] : null) : [],
            'parent'             => $parent,
        ];
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    private static function decodeWords(string $v): string
    {
        if (str_contains($v, '=?')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($d)) {
                $v = $d;
            }
        }
        return self::toUtf8($v, 'UTF-8');
    }

    private static function toUtf8(string $v, string $charset): string
    {
        if (strcasecmp($charset, 'UTF-8') !== 0 && strcasecmp($charset, 'us-ascii') !== 0) {
            $c = @mb_convert_encoding($v, 'UTF-8', $charset);
            if (is_string($c)) {
                $v = $c;
            }
        }
        return mb_scrub($v, 'UTF-8');
    }

    // ------------------------------------------------------------ tokenizer

    /** @return array<int,mixed> one parenthesised list; $i is on its '(' and ends after its ')' */
    private static function readList(string $s, int &$i, int $depth): array
    {
        if ($depth > self::MAX_DEPTH * 3) {
            throw new \InvalidArgumentException('BODYSTRUCTURE: nested too deep');
        }
        $i++;   // '('
        $out = [];
        $len = strlen($s);
        while (true) {
            self::skipSpace($s, $i);
            if ($i >= $len) {
                throw new \InvalidArgumentException('BODYSTRUCTURE: unterminated list');
            }
            $c = $s[$i];
            if ($c === ')') {
                $i++;
                return $out;
            }
            if ($c === '(') {
                $out[] = self::readList($s, $i, $depth + 1);
            } elseif ($c === '"') {
                $out[] = self::readQuoted($s, $i);
            } elseif ($c === '{') {
                $out[] = self::readLiteral($s, $i);
            } else {
                $atom = self::readAtom($s, $i);
                $out[] = strcasecmp($atom, 'NIL') === 0 ? null : $atom;
            }
        }
    }

    private static function readQuoted(string $s, int &$i): string
    {
        $i++;
        $out = '';
        $len = strlen($s);
        while ($i < $len) {
            $c = $s[$i++];
            if ($c === '\\' && $i < $len) {
                $out .= $s[$i++];
            } elseif ($c === '"') {
                return $out;
            } else {
                $out .= $c;
            }
        }
        throw new \InvalidArgumentException('BODYSTRUCTURE: unterminated string');
    }

    private static function readLiteral(string $s, int &$i): string
    {
        if (!preg_match('/\G\{(\d{1,9})\}\r?\n/', $s, $m, 0, $i)) {
            throw new \InvalidArgumentException('BODYSTRUCTURE: bad literal');
        }
        $n  = (int) $m[1];
        $i += strlen($m[0]);
        if ($i + $n > strlen($s)) {
            throw new \InvalidArgumentException('BODYSTRUCTURE: short literal');
        }
        $v  = substr($s, $i, $n);
        $i += $n;
        return $v;
    }

    private static function readAtom(string $s, int &$i): string
    {
        $start = $i;
        $len   = strlen($s);
        while ($i < $len && strpos(" ()\"\r\n\t{", $s[$i]) === false) {
            $i++;
        }
        if ($i === $start) {
            throw new \InvalidArgumentException('BODYSTRUCTURE: unexpected character');
        }
        return substr($s, $start, $i - $start);
    }

    private static function skipSpace(string $s, int &$i): void
    {
        $len = strlen($s);
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\r" || $s[$i] === "\n" || $s[$i] === "\t")) {
            $i++;
        }
    }
}
