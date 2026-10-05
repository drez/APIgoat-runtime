<?php

namespace ApiGoat\Tests\Auth;

use ApiGoat\Auth\EmailChange;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Stateless email-change token: HMAC under a JWT_SECRET-derived key, bound
 * to the current email's fingerprint, expiring. DB-free (confirm() is
 * covered in the projects).
 */
final class EmailChangeTokenTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('JWT_SECRET=test-secret-for-email-change');
        if (!defined('_SITE_URL')) {
            define('_SITE_URL', 'https://example.test/.admin/');
        }
    }

    private function call(string $m, ...$args)
    {
        $r = new ReflectionMethod(EmailChange::class, $m);
        $r->setAccessible(true);
        return $r->invoke(null, ...$args);
    }

    private function payload(): array
    {
        return ['u' => 7, 'e' => 'new@example.test', 'o' => hash('sha256', 'old@example.test'), 'x' => time() + 60];
    }

    public function testSignedTokenVerifies(): void
    {
        $t = $this->call('sign', $this->payload());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $t);
        $this->assertSame('new@example.test', $this->call('verify', $t)['e']);
    }

    public function testTamperedOrForeignTokensAreRefused(): void
    {
        $t = $this->call('sign', $this->payload());
        [$body, $sig] = explode('.', $t);
        $evil = rtrim(strtr(base64_encode(json_encode(['e' => 'attacker@example.test'] + $this->payload())), '+/', '-_'), '=');
        $this->assertNull($this->call('verify', $evil . '.' . $sig), 'payload swap breaks the MAC');
        $this->assertNull($this->call('verify', $body . '.' . strrev($sig)));
        $this->assertNull($this->call('verify', 'garbage'));

        putenv('JWT_SECRET=another-project');
        $this->assertNull($this->call('verify', $t), "another project's secret does not verify");
    }

    public function testNoSecretNoToken(): void
    {
        putenv('JWT_SECRET=');
        $this->assertNull($this->call('sign', $this->payload()));
    }

    public function testDefaultLinkIsTheBackendPage(): void
    {
        putenv('GC_AUTH_EMAIL_CONFIRM_URL=');
        $this->assertStringStartsWith(_SITE_URL . 'Authy/confirmEmail?t=', EmailChange::confirmUrl('abc.def'));
    }
}
