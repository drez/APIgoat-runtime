<?php

namespace ApiGoat\Http;

/**
 * Resolves the real visitor address when this backend sits behind a trusted
 * reverse proxy, or behind a server-rendering front end that calls the API on
 * the visitor's behalf.
 *
 * Why it exists: every IP-keyed protection in a generated project — the
 * registration cap, the login lockout, the password-reset throttle, the auth
 * log — reads $_SERVER['REMOTE_ADDR']. When a Node/SSR tier proxies requests,
 * that address is the FRONT END's for every visitor, so all of those per-IP
 * limits silently collapse into one bucket shared by the whole site: ten
 * signups from anywhere lock out the eleventh person, and one abuser can
 * deny the entire service. Normalising REMOTE_ADDR once, at the entry point,
 * repairs every call site without touching any of them.
 *
 * Spoofing: ONE forwarded header (GC_CLIENT_IP_HEADER, default
 * X-Forwarded-For read right-most-untrusted) is honoured, and ONLY when the
 * connection itself comes from an address in the configured trust list. Anyone connecting
 * directly keeps their real REMOTE_ADDR, so the header can never be used to
 * dodge a limit. With no trust list configured this class does nothing.
 */
final class ClientIp
{
    /**
     * The one forwarded header honoured by default. SECURITY: only a single
     * configured header is ever read (GC_CLIENT_IP_HEADER, e.g. X-Client-Ip
     * for an SSR tier or CF-Connecting-IP behind Cloudflare). The old
     * "first present of X-Client-Ip / CF-Connecting-IP / X-Forwarded-For /
     * X-Real-IP" let a client send whichever one its proxy does not
     * overwrite and pick its own address to dodge lockouts.
     */
    public const DEFAULT_HEADER = 'HTTP_X_FORWARDED_FOR';

    /** 'X-Client-Ip' / 'CF-Connecting-IP' / 'HTTP_X_REAL_IP' → $_SERVER key. */
    public static function serverKey(?string $header): string
    {
        $header = strtoupper(str_replace('-', '_', trim((string) $header)));
        if ($header === '') {
            return self::DEFAULT_HEADER;
        }
        return strncmp($header, 'HTTP_', 5) === 0 ? $header : 'HTTP_' . $header;
    }

    /** Parse a comma/space separated trust list into exact addresses. */
    public static function trustList(?string $list): array
    {
        if ($list === null || trim($list) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($list)) as $entry) {
            $entry = trim((string) $entry);
            if ($entry !== '') {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * The forwarded client address, or null when the caller is not trusted or
     * supplied nothing usable.
     */
    public static function resolve(array $server, array $trusted, ?string $header = null): ?string
    {
        if ($trusted === []) {
            return null;
        }
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !in_array($remote, $trusted, true)) {
            return null;
        }

        $raw = (string) ($server[self::serverKey($header)] ?? '');
        if ($raw === '') {
            return null; // never fall through to another client-settable header
        }
        // A forwarded chain is APPENDED to by each proxy, so read from the
        // right: skip our own trusted hops, and the first other entry is the
        // one the nearest trusted proxy saw. Everything left of it may have
        // been supplied by the client. A single-value header is a chain of one.
        $candidate = null;
        foreach (array_reverse(explode(',', $raw)) as $entry) {
            $entry = trim($entry);
            // Strip an IPv6 bracket/port form such as [::1]:1234.
            if (preg_match('/^\[(.+)\](?::\d+)?$/', $entry, $m)) {
                $entry = $m[1];
            }
            if ($entry === '' || filter_var($entry, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            $candidate = $entry;
            if (!in_array($entry, $trusted, true)) {
                return $entry;
            }
        }
        return $candidate; // every hop trusted: the left-most one
    }

    /**
     * Rewrite REMOTE_ADDR in place with the resolved visitor address, keeping
     * the proxy's own address available as GC_PROXY_ADDR for diagnostics.
     * Safe to call unconditionally: a no-op unless the caller is trusted.
     */
    public static function normalize(array &$server, ?string $trustList, ?string $header = null): void
    {
        if ($header === null) {
            $v = \function_exists('env') ? env('GC_CLIENT_IP_HEADER') : \getenv('GC_CLIENT_IP_HEADER');
            $header = \is_string($v) ? $v : null;
        }
        $client = self::resolve($server, self::trustList($trustList), $header);
        if ($client === null) {
            return;
        }
        $server['GC_PROXY_ADDR'] = $server['REMOTE_ADDR'] ?? '';
        $server['REMOTE_ADDR'] = $client;
    }
}
