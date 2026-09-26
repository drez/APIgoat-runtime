<?php

declare(strict_types=1);

namespace ApiGoat\Tests\OAuth;

use ApiGoat\OAuth\OAuthServerFactory;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;

/**
 * league/oauth2-server encrypts refresh tokens / auth codes with Defuse. Handed
 * a plain string it uses Crypto::*WithPassword — 100,000 PBKDF2 rounds per
 * call, ~400 ms per refresh on prod (every /oauth/token took 650-900 ms).
 * The factory now derives a real Defuse Key from the same OAUTH_ENCRYPTION_KEY.
 */
final class OAuthEncryptionKeyTest extends TestCase
{
    public function test_derives_a_stable_defuse_key_from_the_secret(): void
    {
        $a = OAuthServerFactory::encryptionKeyFor('secret-one');
        $this->assertInstanceOf(Key::class, $a);
        $this->assertSame($a->saveToAsciiSafeString(), OAuthServerFactory::encryptionKeyFor('secret-one')->saveToAsciiSafeString(), 'same secret, same key (every FPM worker must agree)');
        $this->assertNotSame($a->saveToAsciiSafeString(), OAuthServerFactory::encryptionKeyFor('secret-two')->saveToAsciiSafeString());
    }

    public function test_key_mode_round_trips_and_is_fast(): void
    {
        $key = OAuthServerFactory::encryptionKeyFor('secret-one');
        $start = microtime(true);
        $this->assertSame('payload', Crypto::decrypt(Crypto::encrypt('payload', $key), $key));
        $this->assertLessThan(50, (microtime(true) - $start) * 1000, 'no PBKDF2 stretching');
    }

    public function test_an_unset_secret_stays_unset(): void
    {
        $this->assertSame('', OAuthServerFactory::encryptionKeyFor(''));
    }

    /** The grants' own CryptTrait must accept the Key (league 8.5 branches on instanceof Key). */
    public function test_the_refresh_grant_round_trips_with_the_derived_key(): void
    {
        $grant = new \League\OAuth2\Server\Grant\RefreshTokenGrant(
            $this->createMock(\League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface::class)
        );
        $grant->setEncryptionKey(OAuthServerFactory::encryptionKeyFor('secret-one'));
        $enc = new \ReflectionMethod($grant, 'encrypt');
        $dec = new \ReflectionMethod($grant, 'decrypt');
        $start = microtime(true);
        $cipher = $enc->invoke($grant, '{"refresh_token_id":"x"}');
        $this->assertSame('{"refresh_token_id":"x"}', $dec->invoke($grant, $cipher));
        $this->assertLessThan(50, (microtime(true) - $start) * 1000);

        // A token minted under the old password mode no longer decrypts:
        // its holder signs in again once (documented on encryptionKeyFor()).
        $old = \Defuse\Crypto\Crypto::encryptWithPassword('{"refresh_token_id":"x"}', 'secret-one');
        $this->expectException(\LogicException::class);
        $dec->invoke($grant, $old);
    }
}
