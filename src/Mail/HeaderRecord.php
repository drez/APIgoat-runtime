<?php

namespace ApiGoat\Mail;

/**
 * The normalised header record every connector returns from fetchHeaders().
 * Same keys, same types, regardless of provider — the ingest side inserts
 * these straight into `mail_message` (UNIQUE(id_mailbox, provider_message_id)
 * is the idempotency key, so a re-fetch is harmless).
 */
final class HeaderRecord
{
    public const SNIPPET_MAX = 255;

    public const KEYS = [
        'provider_message_id', 'thread_id', 'message_id_header', 'in_reply_to',
        'from_addr', 'from_name', 'to', 'cc', 'subject', 'date_sent', 'snippet',
        'size_bytes', 'has_attachments', 'folder_at_fetch', 'was_read_at_fetch', 'labels',
        'auth_results', 'list_id', 'list_unsubscribe', 'precedence', 'auto_submitted',
    ];

    /**
     * The one Authentication-Results header (RFC 8601) worth reading.
     *
     * Every receiving hop PREPENDS its own header, so the topmost one was
     * written by the server that handed the message to our mailbox — the
     * only hop we trust. Anything below it arrived inside the message, and a
     * sender can put whatever it likes there ("dkim=pass header.d=apple.com"
     * costs nothing to type). Connectors therefore carry the FIRST occurrence
     * and nothing else; '' when the server stamped none.
     */
    public const AUTH_RESULTS_HEADER = 'Authentication-Results';

    /** The bulk-mail markers (sub-project T): record key => header name. Topmost occurrence only, like Authentication-Results. */
    public const BULK_HEADERS = [
        'list_id'          => 'List-Id',
        'list_unsubscribe' => 'List-Unsubscribe',
        'precedence'       => 'Precedence',
        'auto_submitted'   => 'Auto-Submitted',
    ];

    public const BULK_MAX = 500;

    /**
     * The four bulk markers of a raw RFC 822 header block (topmostHeader()
     * each: unfolded, whitespace collapsed, '' when absent). The body is never
     * searched.
     *
     * @return array{list_id:string, list_unsubscribe:string, precedence:string, auto_submitted:string}
     */
    public static function bulkHeaders(string $raw): array
    {
        $out = [];
        foreach (self::BULK_HEADERS as $key => $name) {
            $out[$key] = self::bulkValue(self::topmostHeader($raw, $name));
        }
        return $out;
    }

    private static function bulkValue(mixed $v): string
    {
        $v = trim((string) preg_replace('/\s+/', ' ', (string) ($v ?? '')));
        return mb_substr($v, 0, self::BULK_MAX, 'UTF-8');
    }

    /**
     * Fill every key with a typed default, coerce what's present, clamp the snippet.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function normalise(array $in): array
    {
        $to = $in['to'] ?? [];
        $cc = $in['cc'] ?? [];
        return [
            'provider_message_id' => (string) ($in['provider_message_id'] ?? ''),
            'thread_id'           => self::nullableString($in['thread_id'] ?? null),
            'message_id_header'   => self::messageId($in['message_id_header'] ?? null),
            'in_reply_to'         => self::messageId($in['in_reply_to'] ?? null),
            'from_addr'           => strtolower(trim((string) ($in['from_addr'] ?? ''))),
            'from_name'           => trim((string) ($in['from_name'] ?? '')),
            'to'                  => is_array($to) ? array_values($to) : self::parseAddressList((string) $to),
            'cc'                  => is_array($cc) ? array_values($cc) : self::parseAddressList((string) $cc),
            'subject'             => trim((string) ($in['subject'] ?? '')),
            'date_sent'           => self::dateTime($in['date_sent'] ?? null),
            'snippet'             => self::snippet((string) ($in['snippet'] ?? '')),
            'size_bytes'          => (int) ($in['size_bytes'] ?? 0),
            'has_attachments'     => (bool) ($in['has_attachments'] ?? false),
            'folder_at_fetch'     => (string) ($in['folder_at_fetch'] ?? ''),
            'was_read_at_fetch'   => (bool) ($in['was_read_at_fetch'] ?? false),
            'labels'              => array_values(array_map('strval', (array) ($in['labels'] ?? []))),
            'auth_results'        => trim((string) preg_replace('/\s+/', ' ', (string) ($in['auth_results'] ?? ''))),
            'list_id'             => self::bulkValue($in['list_id'] ?? ''),
            'list_unsubscribe'    => self::bulkValue($in['list_unsubscribe'] ?? ''),
            'precedence'          => self::bulkValue($in['precedence'] ?? ''),
            'auto_submitted'      => self::bulkValue($in['auto_submitted'] ?? ''),
        ];
    }

    /**
     * The value of the FIRST (topmost) $name header in a raw RFC 822 header
     * block, unfolded (continuation lines joined with one space); '' when
     * absent. Reading the raw text instead of a library's parsed header is
     * deliberate: parsers merge repeated headers into a list whose order is
     * theirs, and for Authentication-Results the order IS the trust (see
     * AUTH_RESULTS_HEADER). Stops at the first blank line, so a body that
     * happens to be passed along is never searched.
     */
    public static function topmostHeader(string $raw, string $name): string
    {
        $head  = preg_split('/\r?\n\r?\n/', $raw, 2)[0] ?? '';
        $lines = preg_split('/\r?\n/', $head) ?: [];
        $want  = strtolower($name) . ':';
        $value = null;
        foreach ($lines as $line) {
            if ($value !== null) {
                if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                    $value .= ' ' . trim($line);
                    continue;
                }
                break;
            }
            if (strncasecmp($line, $want, strlen($want)) === 0) {
                $value = trim(substr($line, strlen($want)));
            }
        }
        return $value === null ? '' : trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** Whitespace-collapsed, ≤ SNIPPET_MAX chars (multibyte-safe). */
    public static function snippet(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($s, 'UTF-8') > self::SNIPPET_MAX) {
            $s = rtrim(mb_substr($s, 0, self::SNIPPET_MAX - 1, 'UTF-8')) . '…';
        }
        return $s;
    }

    /**
     * "Ada Lovelace <ada@example.com>" → ['addr' => 'ada@example.com', 'name' => 'Ada Lovelace'].
     * Bare addresses and quoted display names both work; RFC 2047 encoded
     * words in the name are decoded.
     *
     * @return array{addr:string, name:string}
     */
    public static function parseAddress(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['addr' => '', 'name' => ''];
        if (preg_match('/^(.*?)\s*<([^<>]+)>\s*$/s', $raw, $m)) {
            $name = trim($m[1], " \t\"'");
            return ['addr' => strtolower(trim($m[2])), 'name' => self::decodeWords($name)];
        }
        if (preg_match('/([^\s<>"\',;]+@[^\s<>"\',;]+)/', $raw, $m)) {
            $name = trim(str_replace($m[1], '', $raw), " \t\"'()");
            return ['addr' => strtolower($m[1]), 'name' => self::decodeWords($name)];
        }
        return ['addr' => '', 'name' => self::decodeWords(trim($raw, " \t\"'"))];
    }

    /**
     * Split a To:/Cc: header on commas that are outside quotes / angle brackets.
     *
     * @return array<int,array{addr:string, name:string}>
     */
    public static function parseAddressList(string $raw): array
    {
        $out = [];
        $buf = '';
        $inQuote = false;
        $depth = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            if ($c === '"' && ($i === 0 || $raw[$i - 1] !== '\\')) $inQuote = !$inQuote;
            elseif (!$inQuote && $c === '<') $depth++;
            elseif (!$inQuote && $c === '>') $depth = max(0, $depth - 1);
            if ($c === ',' && !$inQuote && $depth === 0) {
                $out[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $out[] = $buf;
        $parsed = [];
        foreach ($out as $piece) {
            if (trim($piece) === '') continue;
            $a = self::parseAddress($piece);
            if ($a['addr'] !== '' || $a['name'] !== '') $parsed[] = $a;
        }
        return $parsed;
    }

    /** RFC 2047 encoded-word decode (=?utf-8?B?…?=) with a safe fallback. */
    public static function decodeWords(string $s): string
    {
        if (strpos($s, '=?') === false) return $s;
        if (function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false) return $d;
        }
        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($s);
        }
        return $s;
    }

    /** "<abc@x>" → "abc@x"; null/empty stays null. */
    public static function messageId(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') return null;
        // In-Reply-To may list several ids; keep the first.
        if (preg_match('/<([^<>]+)>/', $v, $m)) return $m[1];
        return $v;
    }

    /** Anything strtotime/DateTime understands → 'Y-m-d H:i:s' UTC, else null. */
    public static function dateTime(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) {
            return (clone $v)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (is_int($v) || (is_string($v) && ctype_digit($v))) {
            return gmdate('Y-m-d H:i:s', (int) $v);
        }
        $s = (string) $v;
        // Strip "(UTC)"-style comments some MTAs append.
        $s = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $s));
        try {
            return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $ts = strtotime($s);
            return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
        }
    }

    private static function nullableString(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : $v;
    }
}
