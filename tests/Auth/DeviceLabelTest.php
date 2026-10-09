<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\DeviceLabel;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Auth/DeviceLabel.php';

final class DeviceLabelTest extends TestCase
{
    /** @dataProvider uas */
    public function testLabels(?string $ua, string $expected): void
    {
        $this->assertSame($expected, DeviceLabel::fromUserAgent($ua));
    }

    public static function uas(): array
    {
        return [
            'chrome win'  => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome on Windows'],
            'edge win'    => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Edge on Windows'],
            'firefox lin' => ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox on Linux'],
            'safari mac'  => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15', 'Safari on macOS'],
            'safari ios'  => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Version/17.1 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
            'chrome ios'  => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 CriOS/120.0 Mobile/15E148 Safari/604.1', 'Chrome on iOS'],
            'chrome droid'=> ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36', 'Chrome on Android'],
            'expo ios'    => ['Expo/1017 CFNetwork/1494.0.7 Darwin/23.4.0', 'apigmail app on iOS'],
            'okhttp'      => ['okhttp/4.12.0', 'apigmail app on Android'],
            'cfnetwork'   => ['apigmail/1.0 CFNetwork/1494.0.7 Darwin/23.4.0', 'apigmail app on iOS'],
            'empty'       => ['', 'Unknown device'],
            'null'        => [null, 'Unknown device'],
            'curl'        => ['curl/8.4.0', 'Unknown device'],
        ];
    }

    public function testMaskIp(): void
    {
        $this->assertSame('203.0.x.x', DeviceLabel::maskIp('203.0.113.9'));
        $this->assertSame('2001:db8:85a3::…', DeviceLabel::maskIp('2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
        $this->assertSame('2001:db8:0::…', DeviceLabel::maskIp('2001:db8::1'));
        $this->assertSame('10.1.x.x', DeviceLabel::maskIp('::ffff:10.1.2.3'));
        $this->assertNull(DeviceLabel::maskIp(''));
        $this->assertNull(DeviceLabel::maskIp(null));
        $this->assertNull(DeviceLabel::maskIp('not-an-ip'));
    }
}
