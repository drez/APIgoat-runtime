<?php

declare(strict_types=1);

namespace ApiGoat\Auth;

/**
 * Human labels for the "signed-in devices" list: a User-Agent turned into
 * "<Browser> on <OS>" and an IP address masked for display.
 *
 * Best effort and display-only: nothing here is a security decision.
 */
final class DeviceLabel
{
    public const UNKNOWN = 'Unknown device';

    public static function fromUserAgent(?string $ua): string
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return self::UNKNOWN;
        }
        $os = self::os($ua);

        // Native clients first: the Expo/okhttp/CFNetwork stacks carry no browser token.
        $native = stripos($ua, 'Expo') !== false || stripos($ua, 'okhttp') !== false
            || (!preg_match('~(?:Chrome|CriOS|Firefox|FxiOS|Edg|EdgiOS|EdgA|Version/[\d.]+.*Safari|Safari)/~', $ua)
                && (stripos($ua, 'CFNetwork') !== false || stripos($ua, 'Darwin/') !== false));
        if ($native) {
            return 'apigmail app on ' . ($os ?? (stripos($ua, 'okhttp') !== false ? 'Android' : 'iOS'));
        }

        // Order matters: Edge and Chrome UAs also say Safari; Chrome UAs say Chrome only.
        if (preg_match('~\b(?:Edg|EdgA|EdgiOS)/~', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('~\b(?:Firefox|FxiOS)/~', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('~\b(?:Chrome|CriOS)/~', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('~\bSafari/~', $ua)) {
            $browser = 'Safari';
        } else {
            $browser = null;
        }
        if ($browser === null && $os === null) {
            return self::UNKNOWN;
        }
        if ($browser === null) {
            return 'Unknown browser on ' . $os;
        }
        return $os === null ? $browser : $browser . ' on ' . $os;
    }

    private static function os(string $ua): ?string
    {
        // iOS before macOS (iPad/iPhone UAs say "like Mac OS X"); Android before Linux.
        if (preg_match('~iPhone|iPad|iPod|iOS|CFNetwork/.*Darwin~i', $ua)) {
            return 'iOS';
        }
        if (stripos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Linux') !== false || stripos($ua, 'X11') !== false || stripos($ua, 'CrOS') !== false) {
            return 'Linux';
        }
        return null;
    }

    /** IPv4 "a.b.x.x"; IPv6 first three groups + "::…"; anything else (or empty) null. */
    public static function maskIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.x.x';
        }
        $bin = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? @inet_pton($ip) : false;
        if ($bin !== false && strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) is an IPv4 client behind a dual-stack socket.
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                return self::maskIp(inet_ntop(substr($bin, 12)) ?: null);
            }
            $groups = array_map(static fn (string $g): string => ltrim(bin2hex($g), '0') ?: '0', str_split(substr($bin, 0, 6), 2));
            return implode(':', $groups) . '::…';
        }
        return null;
    }
}
