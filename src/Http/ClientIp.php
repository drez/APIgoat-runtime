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
 * Spoofing: a forwarded header is honoured ONLY when the connection itself
 * comes from an address in the configured trust list. Anyone connecting
 * directly keeps their real REMOTE_ADDR, so the header can never be used to
 * dodge a limit. With no trust list configured this class does nothing.
 */
final class ClientIp
{
    /**
     * Forwarded headers, most explicit first. X-Client-Ip is the app's own
     * deliberate hand-off; the rest are set by common proxies/CDNs.
     */
    private const HEADERS = [
        'HTTP_X_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
    ];

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
    public static function resolve(array $server, array $trusted): ?string
    {
        if ($trusted === []) {
            return null;
        }
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote === '' || !in_array($remote, $trusted, true)) {
            return null;
        }

        foreach (self::HEADERS as $header) {
            $raw = (string) ($server[$header] ?? '');
            if ($raw === '') {
                continue;
            }
            // X-Forwarded-For is a chain each proxy APPENDS to, so the
            // right-most entry is the one our own nearest trusted hop wrote,
            // and everything left of it may have been supplied by the client.
            // Read from the right: trusting the left-most would let a caller
            // invent an address per request and evade the limits this exists
            // to enforce. (X-Client-Ip is a single value we set ourselves.)
            $chain = explode(',', $raw);
            if ($header === 'HTTP_X_FORWARDED_FOR') {
                $chain = array_reverse($chain);
            }
            foreach ($chain as $candidate) {
                $candidate = trim($candidate);
                // Strip an IPv6 bracket/port form such as [::1]:1234.
                if (preg_match('/^\[(.+)\](?::\d+)?$/', $candidate, $m)) {
                    $candidate = $m[1];
                }
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * Rewrite REMOTE_ADDR in place with the resolved visitor address, keeping
     * the proxy's own address available as GC_PROXY_ADDR for diagnostics.
     * Safe to call unconditionally: a no-op unless the caller is trusted.
     */
    public static function normalize(array &$server, ?string $trustList): void
    {
        $client = self::resolve($server, self::trustList($trustList));
        if ($client === null) {
            return;
        }
        $server['GC_PROXY_ADDR'] = $server['REMOTE_ADDR'] ?? '';
        $server['REMOTE_ADDR'] = $client;
    }
}
