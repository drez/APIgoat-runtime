<?php

declare(strict_types=1);

namespace ApiGoat\Tests\OAuth;

use ApiGoat\OAuth\ClientRepository;
use PHPUnit\Framework\TestCase;

/**
 * OAuth client secrets are server-minted (bin2hex(random_bytes(32)), 256
 * bits), never human passwords. bcrypt — cost 12 by default since PHP 8.4 —
 * cost ~280 ms on EVERY /oauth/token call of a confidential client (the
 * Claude MCP connector refreshes hourly) and buys nothing against a secret
 * that cannot be guessed. They are now stored as sha256$<hex>, compared in
 * constant time; legacy bcrypt rows still verify and are upgraded on the
 * first correct secret.
 */
final class ClientSecretHashTest extends TestCase
{
    public function test_new_secrets_hash_fast_and_verify(): void
    {
        $secret = bin2hex(random_bytes(32));
        $stored = ClientRepository::hashSecret($secret);
        $this->assertStringStartsWith('sha256$', $stored);
        $this->assertSame(71, strlen($stored), 'fits client_secret_hash varchar(255)');

        $start = microtime(true);
        $this->assertSame(['ok' => true, 'rehash' => null], ClientRepository::verifySecret($secret, $stored));
        $this->assertLessThan(5, (microtime(true) - $start) * 1000);

        $this->assertSame(['ok' => false, 'rehash' => null], ClientRepository::verifySecret($secret . 'x', $stored));
        $this->assertFalse(ClientRepository::verifySecret('', $stored)['ok']);
    }

    public function test_legacy_bcrypt_verifies_and_upgrades_only_on_a_correct_secret(): void
    {
        $secret = bin2hex(random_bytes(32));
        $legacy = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 4]);

        $ok = ClientRepository::verifySecret($secret, $legacy);
        $this->assertTrue($ok['ok']);
        $this->assertSame(ClientRepository::hashSecret($secret), $ok['rehash'], 'upgrade to the fast format');

        $bad = ClientRepository::verifySecret('wrong', $legacy);
        $this->assertSame(['ok' => false, 'rehash' => null], $bad, 'never rewrite a hash on a failed attempt');
    }

    public function test_unknown_or_malformed_stored_values_fail_closed(): void
    {
        $this->assertFalse(ClientRepository::verifySecret('s', '')['ok']);
        $this->assertFalse(ClientRepository::verifySecret('s', 'sha256$')['ok']);
        $this->assertFalse(ClientRepository::verifySecret('s', 'plaintext-s')['ok']);
        $this->assertFalse(ClientRepository::verifySecret('s', 'md5$' . md5('s'))['ok']);
    }
}
