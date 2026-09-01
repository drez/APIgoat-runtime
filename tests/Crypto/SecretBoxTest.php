<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Crypto;

use ApiGoat\Crypto\CryptoException;
use ApiGoat\Crypto\SecretBox;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Crypto/CryptoException.php';
require_once __DIR__ . '/../../src/Crypto/SecretBox.php';

final class SecretBoxTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        \putenv('APP_SECRET_KEY_V1');
        unset($_ENV['APP_SECRET_KEY_V1']);
        $this->key = \base64_encode(\random_bytes(32));
        SecretBox::setMasterKey($this->key);
    }

    protected function tearDown(): void
    {
        SecretBox::setMasterKey(null);
        \putenv('APP_SECRET_KEY_V1');
        unset($_ENV['APP_SECRET_KEY_V1']);
    }

    public function testRoundTripAndFormat(): void
    {
        $plain = 'imap-password-🔑-' . \str_repeat('x', 200);
        $c = SecretBox::seal($plain, 42);
        self::assertMatchesRegularExpression('/^v1:[A-Za-z0-9+\/]+=*$/', $c);
        self::assertStringNotContainsString('imap-password', $c);
        self::assertSame($plain, SecretBox::open($c, 42));
        self::assertNotSame($c, SecretBox::seal($plain, 42), 'fresh nonce every seal');
        self::assertSame('', SecretBox::open(SecretBox::seal('', 0), 0), 'empty plaintext + tenant 0 ok');
        self::assertSame(1, SecretBox::keyVersion());
        self::assertTrue(SecretBox::available());
    }

    public function testCrossTenantOpenFails(): void
    {
        $c = SecretBox::seal('secret', 1);
        $this->expectException(CryptoException::class);
        SecretBox::open($c, 2);
    }

    public function testTamperedCiphertextFails(): void
    {
        $c = SecretBox::seal('secret', 5);
        $bin = \base64_decode(\substr($c, 3), true);
        $bin[\strlen($bin) - 1] = \chr(\ord($bin[\strlen($bin) - 1]) ^ 0x01);
        $this->expectException(CryptoException::class);
        SecretBox::open('v1:' . \base64_encode($bin), 5);
    }

    public function testMalformedAndWrongVersionFail(): void
    {
        foreach (['', 'v1:', 'nope', 'v2:' . \base64_encode(\random_bytes(60)), 'v1:!!!', 'v1:' . \base64_encode('short')] as $bad) {
            try {
                SecretBox::open($bad, 1);
                self::fail('expected CryptoException for ' . \var_export($bad, true));
            } catch (CryptoException $e) {
                self::assertStringNotContainsString('secret', $e->getMessage());
            }
        }
    }

    public function testMissingKeyThrowsAndAvailableIsFalse(): void
    {
        SecretBox::setMasterKey(null);
        self::assertFalse(SecretBox::available());
        $this->expectException(CryptoException::class);
        SecretBox::seal('x', 1);
    }

    public function testKeyFromEnvBase64OrHex(): void
    {
        SecretBox::setMasterKey(null);
        $raw = \random_bytes(32);
        \putenv('APP_SECRET_KEY_V1=' . \base64_encode($raw));
        $c = SecretBox::seal('via-b64', 9);
        \putenv('APP_SECRET_KEY_V1=' . \bin2hex($raw));
        self::assertSame('via-b64', SecretBox::open($c, 9), 'hex and base64 spell the same key');

        \putenv('APP_SECRET_KEY_V1=' . \base64_encode('too-short'));
        self::assertFalse(SecretBox::available());
        $this->expectException(CryptoException::class);
        SecretBox::open($c, 9);
    }

    public function testFingerprint(): void
    {
        self::assertSame(\substr(\hash('sha256', 'abc'), 0, 12), SecretBox::fingerprint('abc'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', SecretBox::fingerprint('anything'));
    }
}
