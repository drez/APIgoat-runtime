<?php

namespace ApiGoat\Tests\Http;

use ApiGoat\Http\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * Review 2026-09-24: behind a trusted proxy the FIRST present of
 * X-Client-Ip / CF-Connecting-IP / X-Forwarded-For / X-Real-IP won, so a
 * client sent whichever header its proxy does not overwrite and chose its
 * own address to dodge the login lockout. Only one configured header
 * (GC_CLIENT_IP_HEADER, default X-Forwarded-For right-most-untrusted) counts.
 */
final class ClientIpTest extends TestCase
{
    private const PROXY = ['10.0.0.1'];

    public function testUntrustedCallerKeepsRemoteAddr(): void
    {
        self::assertNull(ClientIp::resolve(['REMOTE_ADDR' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'], self::PROXY));
        self::assertNull(ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'], []));
    }

    public function testDefaultIsRightMostUntrustedForwardedFor(): void
    {
        $s = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.7'];
        self::assertSame('203.0.113.7', ClientIp::resolve($s, self::PROXY));
        // our own trusted hops on the right are skipped
        $s['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 203.0.113.7, 10.0.0.1';
        self::assertSame('203.0.113.7', ClientIp::resolve($s, self::PROXY));
        $s['HTTP_X_FORWARDED_FOR'] = '[2001:db8::1]:443';
        self::assertSame('2001:db8::1', ClientIp::resolve($s, self::PROXY));
        $s['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, garbage';
        self::assertNull(ClientIp::resolve($s, self::PROXY), 'unreadable nearest hop: no guess from the left');
    }

    public function testOtherClientSettableHeadersAreIgnoredByDefault(): void
    {
        $s = [
            'REMOTE_ADDR'           => '10.0.0.1',
            'HTTP_X_CLIENT_IP'      => '1.2.3.4',
            'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
            'HTTP_X_REAL_IP'        => '7.7.7.7',
            'HTTP_X_FORWARDED_FOR'  => '203.0.113.7',
        ];
        self::assertSame('203.0.113.7', ClientIp::resolve($s, self::PROXY));
        unset($s['HTTP_X_FORWARDED_FOR']);
        self::assertNull(ClientIp::resolve($s, self::PROXY), 'no fall-through to another header');
    }

    public function testConfiguredHeaderIsTheOnlyOneRead(): void
    {
        $s = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
        self::assertSame('5.6.7.8', ClientIp::resolve($s, self::PROXY, 'CF-Connecting-IP'));
        self::assertSame('5.6.7.8', ClientIp::resolve($s, self::PROXY, 'HTTP_CF_CONNECTING_IP'));
        self::assertNull(ClientIp::resolve($s, self::PROXY, 'X-Client-Ip'));
        self::assertSame('HTTP_X_FORWARDED_FOR', ClientIp::serverKey(''));
        self::assertSame('HTTP_X_CLIENT_IP', ClientIp::serverKey('x-client-ip'));
    }

    public function testNormalizeReadsTheEnvHeader(): void
    {
        putenv('GC_CLIENT_IP_HEADER=X-Client-Ip');
        try {
            $s = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_CLIENT_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
            ClientIp::normalize($s, '10.0.0.1');
            self::assertSame('1.2.3.4', $s['REMOTE_ADDR']);
            self::assertSame('10.0.0.1', $s['GC_PROXY_ADDR']);
        } finally {
            putenv('GC_CLIENT_IP_HEADER');
        }
        $s = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_CLIENT_IP' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9'];
        ClientIp::normalize($s, '10.0.0.1');
        self::assertSame('9.9.9.9', $s['REMOTE_ADDR'], 'default: X-Forwarded-For');
    }

    public function testHeaderIsChosenPerTrustedProxyForCdnPlusSsr(): void
    {
        // vidifye-style: SSR tier (10.0.0.5) forwards X-Client-Ip, the CDN edge
        // (10.0.0.1) sets CF-Connecting-IP.
        $spec    = 'X-Client-Ip@10.0.0.5, CF-Connecting-IP';
        $trusted = ['10.0.0.1', '10.0.0.5'];
        $ssr = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_CLIENT_IP' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6'];
        self::assertSame('203.0.113.9', ClientIp::resolve($ssr, $trusted, $spec));
        // Through the CDN a client-sent X-Client-Ip is ignored — the CDN path
        // reads CF-Connecting-IP only, and never falls through when it's empty.
        $cdn = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_CLIENT_IP' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '198.51.100.4'];
        self::assertSame('198.51.100.4', ClientIp::resolve($cdn, $trusted, $spec));
        unset($cdn['HTTP_CF_CONNECTING_IP']);
        self::assertNull(ClientIp::resolve($cdn, $trusted, $spec));
        // A single header still works as before; scoped-only spec with no match
        // falls back to the X-Forwarded-For default.
        self::assertSame('X-Client-Ip', ClientIp::headerFor('X-Client-Ip', '10.0.0.9'));
        self::assertSame('', ClientIp::headerFor('X-Client-Ip@10.0.0.5', '10.0.0.9'));
        self::assertSame('X-Client-Ip', ClientIp::headerFor('X-Client-Ip@10.0.0.4|10.0.0.5', '10.0.0.5'));
    }
}
