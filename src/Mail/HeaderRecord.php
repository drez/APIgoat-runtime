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
    ];

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
        ];
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
