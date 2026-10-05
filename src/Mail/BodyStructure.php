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
 * HOSTILE INPUT: the structure is written by whoever sent the mail (and by
 * the server). parse() NEVER throws and is bounded in every dimension —
 * input bytes (MAX_INPUT), tokens (MAX_TOKENS), list nesting (MAX_NESTING),
 * multipart depth (MAX_DEPTH: a deeper subtree is skipped, so every section
 * has at most MAX_DEPTH + 1 components), leaves (MAX_LEAVES) and wall time
 * (MAX_SECONDS). Malformed input (an unterminated list / string, a short
 * literal, a stray byte) stops the tokenizer where it is: the lists still
 * open are closed and what was read is walked, so the result is a clean
 * partial list — or [] when nothing usable was read. A truncated leaf gets
 * neutral defaults (application/octet-stream, 7bit, size 0); callers keep
 * enforcing their own caps on the bytes.
 *
 * Leaf shape:
 *   section:string, type:string, subtype:string (both lower case),
 *   params:array<string,string> (lower-case keys, RFC 2231 / 2047 decoded),
 *   id:?string (Content-ID without <>), description:?string,
 *   encoding:string (lower case, '7bit' when the server gave none),
 *   size:int (ENCODED octets, as the server counts them, ≥ 0),
 *   disposition:?string (lower case), disposition_params:array<string,string>,
 *   parent:?string (the enclosing multipart's subtype, lower case; null at top level)
 */
final class BodyStructure
{
    /** Leaves past this are dropped (a hostile message with thousands of parts). */
    public const MAX_LEAVES = 500;
    /** Multipart nesting walked; a deeper subtree is skipped (sections stay ≤ MAX_DEPTH + 1 components). */
    public const MAX_DEPTH = 30;
    /** Parenthesis nesting the tokenizer follows (envelopes nest a few levels per part). */
    public const MAX_NESTING = 120;
    public const MAX_TOKENS = 200000;
    public const MAX_INPUT = 2097152;
    public const MAX_SECONDS = 0.5;
    private const MAX_PARAM_BYTES = 4096;

    /**
     * @param string $fetch the untagged FETCH response (or just the parenthesised BODYSTRUCTURE)
     * @return list<array<string,mixed>> never throws; [] when nothing usable was read
     */
    public static function parse(string $fetch): array
    {
        try {
            if (strlen($fetch) > self::MAX_INPUT) {
                $fetch = substr($fetch, 0, self::MAX_INPUT);   // a partial structure, closed below
            }
            $pos = stripos($fetch, 'BODYSTRUCTURE');
            $src = $pos === false ? $fetch : substr($fetch, $pos + strlen('BODYSTRUCTURE'));
            $i   = 0;
            self::skipSpace($src, $i);
            if (($src[$i] ?? '') !== '(') {
                return [];
            }
            $tree   = self::tokenize($src, $i);
            $leaves = [];
            self::walk($tree, '', null, $leaves, 0);
            return $leaves;
        } catch (\Throwable) {
            return [];   // belt and braces: nothing here is meant to throw
        }
    }

    /** RFC 2231 continuations / charsets and RFC 2047 encoded words → one UTF-8 value per lower-case key. Never throws. */
    public static function decodeParams(?array $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $plain = [];
        $parts = [];   // name => [index => [value, encoded]]
        $n     = min(count($list), 200);
        for ($k = 0; $k + 1 < $n; $k += 2) {
            if (!is_string($list[$k] ?? null) || !is_string($list[$k + 1] ?? null)) {
                continue;
            }
            $key = strtolower(trim($list[$k]));
            $val = substr($list[$k + 1], 0, self::MAX_PARAM_BYTES);
            if ($key === '') {
                continue;
            }
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
                if (strlen($value) > self::MAX_PARAM_BYTES) {
                    break;
                }
            }
            $out[$name] = self::toUtf8(substr($value, 0, self::MAX_PARAM_BYTES), $charset);   // the 2231 form wins over a plain one
        }
        return $out;
    }

    /** @param array<int,mixed> $node */
    private static function walk(array $node, string $section, ?string $parent, array &$leaves, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || count($leaves) >= self::MAX_LEAVES) {
            return;   // deeper than any section a caller accepts, or enough leaves: skipped
        }
        if (isset($node[0]) && is_array($node[0])) {
            // multipart: (child)(child)… "SUBTYPE" [params dsp lang loc]
            $n = 0;
            while (isset($node[$n]) && is_array($node[$n])) {
                $n++;
            }
            $subtype = is_string($node[$n] ?? null) ? self::token($node[$n], 'mixed') : 'mixed';
            for ($c = 0; $c < $n && count($leaves) < self::MAX_LEAVES; $c++) {
                self::walk($node[$c], $section === '' ? (string) ($c + 1) : $section . '.' . ($c + 1), $subtype, $leaves, $depth + 1);
            }
            return;
        }
        if (!is_string($node[0] ?? null) && !is_string($node[1] ?? null)) {
            return;   // not a body part at all (an empty or truncated list): nothing to list
        }
        $type    = self::token(self::str($node[0] ?? null), 'application');
        $subtype = self::token(self::str($node[1] ?? null), 'octet-stream');
        $dspAt   = 8;
        if ($type === 'text') {
            $dspAt = 9;                       // + lines, md5
        } elseif ($type === 'message' && in_array($subtype, ['rfc822', 'global'], true)) {
            $dspAt = 11;                      // + envelope, body, lines, md5
        }
        $dsp  = $node[$dspAt] ?? null;
        $id   = self::str($node[3] ?? null);
        $id   = $id === null ? null : trim(substr($id, 0, 1000), " \t<>");
        $size = self::str($node[6] ?? null);
        $leaves[] = [
            'section'            => $section === '' ? '1' : $section,
            'type'               => $type,
            'subtype'            => $subtype,
            'params'             => self::decodeParams(is_array($node[2] ?? null) ? $node[2] : null),
            'id'                 => $id !== null && $id !== '' ? $id : null,
            'description'        => ($d = self::str($node[4] ?? null)) === null ? null : self::decodeWords(substr($d, 0, self::MAX_PARAM_BYTES)),
            'encoding'           => self::token(self::str($node[5] ?? null), '7bit'),
            'size'               => $size !== null && ctype_digit($size) ? (strlen($size) > 15 ? PHP_INT_MAX : (int) $size) : 0,
            'disposition'        => is_array($dsp) && is_string($dsp[0] ?? null) ? self::token($dsp[0], 'attachment') : null,
            'disposition_params' => is_array($dsp) ? self::decodeParams(is_array($dsp[1] ?? null) ? $dsp[1] : null) : [],
            'parent'             => $parent,
        ];
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    /** A MIME token (type, subtype, encoding, disposition): lower case, sane, else $default. */
    private static function token(?string $v, string $default): string
    {
        $v = strtolower(trim((string) $v));
        return $v !== '' && strlen($v) <= 100 && preg_match('/^[a-z0-9!#$&^_.+-]+$/', $v) ? $v : $default;
    }

    private static function decodeWords(string $v): string
    {
        if (str_contains($v, '=?')) {
            try {
                $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                if (is_string($d)) {
                    $v = $d;
                }
            } catch (\Throwable) {
            }
        }
        return self::toUtf8($v, 'UTF-8');
    }

    private static function toUtf8(string $v, string $charset): string
    {
        if (strcasecmp($charset, 'UTF-8') !== 0 && strcasecmp($charset, 'us-ascii') !== 0) {
            try {
                // An unknown charset is a ValueError in PHP 8 (not a warning): keep the bytes, scrubbed.
                $c = @mb_convert_encoding($v, 'UTF-8', $charset);
                if (is_string($c)) {
                    $v = $c;
                }
            } catch (\Throwable) {
            }
        }
        return mb_scrub($v, 'UTF-8');
    }

    // ------------------------------------------------------------ tokenizer

    /**
     * The parenthesised list at $i (its '('), iteratively: no recursion, so
     * no stack to exhaust. Stops — closing every open list — at its own ')',
     * the end of input, a malformed token, or any cap.
     *
     * @return array<int,mixed>
     */
    private static function tokenize(string $s, int $i): array
    {
        $len      = strlen($s);
        $deadline = hrtime(true) + (int) (self::MAX_SECONDS * 1e9);
        $tokens   = 0;
        $stack    = [];
        $cur      = [];
        $i++;   // the root '('
        while (true) {
            self::skipSpace($s, $i);
            if ($i >= $len || ++$tokens > self::MAX_TOKENS || ($tokens % 1024 === 0 && hrtime(true) > $deadline)) {
                break;
            }
            $c = $s[$i];
            if ($c === ')') {
                $i++;
                if ($stack === []) {
                    return $cur;
                }
                $parent   = array_pop($stack);
                $parent[] = $cur;
                $cur      = $parent;
                continue;
            }
            if ($c === '(') {
                if (count($stack) >= self::MAX_NESTING) {
                    break;
                }
                $i++;
                $stack[] = $cur;
                $cur     = [];
                continue;
            }
            if ($c === '"') {
                $v = self::readQuoted($s, $i);
            } elseif ($c === '{') {
                $v = self::readLiteral($s, $i);
            } else {
                $v = self::readAtom($s, $i);
                if ($v !== null && strcasecmp($v, 'NIL') === 0) {
                    $cur[] = null;
                    continue;
                }
            }
            if ($v === null) {
                break;   // malformed: stop here, keep what was read
            }
            $cur[] = $v;
        }
        while ($stack !== []) {   // close what is still open
            $parent   = array_pop($stack);
            $parent[] = $cur;
            $cur      = $parent;
        }
        return $cur;
    }

    private static function readQuoted(string $s, int &$i): ?string
    {
        $end = $i + 1;
        $len = strlen($s);
        $out = '';
        while ($end < $len) {
            $c = $s[$end++];
            if ($c === '\\' && $end < $len) {
                $out .= $s[$end++];
            } elseif ($c === '"') {
                $i = $end;
                return $out;
            } else {
                $out .= $c;
            }
        }
        return null;   // unterminated
    }

    private static function readLiteral(string $s, int &$i): ?string
    {
        if (!preg_match('/\G\{(\d{1,9})\}\r?\n/', $s, $m, 0, $i)) {
            return null;
        }
        $n     = (int) $m[1];
        $start = $i + strlen($m[0]);
        if ($start + $n > strlen($s)) {
            return null;   // short literal
        }
        $i = $start + $n;
        return substr($s, $start, $n);
    }

    private static function readAtom(string $s, int &$i): ?string
    {
        $start = $i;
        $len   = strlen($s);
        while ($i < $len && strpos(" ()\"\r\n\t{", $s[$i]) === false) {
            $i++;
        }
        return $i === $start ? null : substr($s, $start, $i - $start);
    }

    private static function skipSpace(string $s, int &$i): void
    {
        $len = strlen($s);
        while ($i < $len && ($s[$i] === ' ' || $s[$i] === "\r" || $s[$i] === "\n" || $s[$i] === "\t")) {
            $i++;
        }
    }
}
