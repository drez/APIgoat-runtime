<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Auth;

require_once __DIR__ . '/../../src/Auth/GoogleRegistration.php';

use ApiGoat\Auth\GoogleRegistration;
use PHPUnit\Framework\TestCase;

/**
 * Self-registration policy for Google sign-in (GIS). Pure env parsing +
 * domain allowlist; the emitted Authy/google handler consults it after the
 * sub-match and email auto-link both miss.
 */
final class GoogleRegistrationTest extends TestCase
{
    private const KEYS = ['GOOGLE_AUTO_REGISTER', 'GOOGLE_AUTO_REGISTER_GROUP', 'GOOGLE_AUTO_REGISTER_DOMAINS'];

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    public function testDisabledByDefault(): void
    {
        $p = GoogleRegistration::fromEnv();
        $this->assertFalse($p->isEnabled());
        $this->assertFalse($p->allowsEmail('a@apigoat.com'));
    }

    /** @dataProvider onValues */
    public function testTruthyValuesEnable(string $raw): void
    {
        putenv('GOOGLE_AUTO_REGISTER=' . $raw);
        $this->assertTrue(GoogleRegistration::fromEnv()->isEnabled(), "'{$raw}' should enable");
    }

    public static function onValues(): array
    {
        return [['1'], ['true'], ['TRUE'], ['yes'], ['on']];
    }

    /** @dataProvider offValues */
    public function testOtherValuesStayDisabled(string $raw): void
    {
        putenv('GOOGLE_AUTO_REGISTER=' . $raw);
        $this->assertFalse(GoogleRegistration::fromEnv()->isEnabled(), "'{$raw}' must not enable");
    }

    public static function offValues(): array
    {
        return [['0'], ['false'], ['no'], ['off'], [''], ['maybe']];
    }

    public function testEnvArrayTakesPrecedenceOverGetenv(): void
    {
        // Projects load .env into $_ENV (adhocore/env); mirror the existing
        // GOOGLE_CLIENT_ID lookup order ($_ENV first, getenv() fallback).
        putenv('GOOGLE_AUTO_REGISTER=1');
        $_ENV['GOOGLE_AUTO_REGISTER'] = '0';
        $this->assertFalse(GoogleRegistration::fromEnv()->isEnabled());
    }

    public function testEnabledWithoutDomainsAllowsAnyEmail(): void
    {
        putenv('GOOGLE_AUTO_REGISTER=1');
        $p = GoogleRegistration::fromEnv();
        $this->assertTrue($p->allowsEmail('someone@gmail.com'));
        $this->assertSame([], $p->domains());
    }

    public function testDomainAllowlistIsCaseInsensitiveAndTrimmed(): void
    {
        putenv('GOOGLE_AUTO_REGISTER=1');
        putenv('GOOGLE_AUTO_REGISTER_DOMAINS=" Apigoat.com , ll-teq.com,,"');
        $p = GoogleRegistration::fromEnv();
        $this->assertSame(['apigoat.com', 'll-teq.com'], $p->domains());
        $this->assertTrue($p->allowsEmail('Fred@APIGOAT.com'));
        $this->assertTrue($p->allowsEmail('x@ll-teq.com'));
        $this->assertFalse($p->allowsEmail('x@gmail.com'));
        $this->assertFalse($p->allowsEmail('x@notapigoat.com'), 'suffix match must not leak');
        $this->assertFalse($p->allowsEmail('x@apigoat.com.evil.io'));
    }

    public function testMalformedEmailIsNeverAllowed(): void
    {
        putenv('GOOGLE_AUTO_REGISTER=1');
        $p = GoogleRegistration::fromEnv();
        $this->assertFalse($p->allowsEmail(''));
        $this->assertFalse($p->allowsEmail('no-at-sign'));
        $this->assertFalse($p->allowsEmail('trailing@'));
    }

    public function testGroupNameDefaultsToNullAndIsTrimmed(): void
    {
        $this->assertNull(GoogleRegistration::fromEnv()->groupName());
        putenv('GOOGLE_AUTO_REGISTER_GROUP="  Learner "');
        $this->assertSame('Learner', GoogleRegistration::fromEnv()->groupName());
        putenv('GOOGLE_AUTO_REGISTER_GROUP=   ');
        $this->assertNull(GoogleRegistration::fromEnv()->groupName());
    }

    public function testEnvValuesAreDequoted(): void
    {
        // adhocore/env keeps surrounding quotes in some load paths; be lenient.
        putenv('GOOGLE_AUTO_REGISTER="1"');
        $this->assertTrue(GoogleRegistration::fromEnv()->isEnabled());
    }

    /**
     * Wave 4: a domain allowlist is a Workspace-domain restriction. Google
     * lets anyone create a consumer account on ANY email address (an
     * "unmanaged" account) with email_verified=true — only the `hd` claim
     * proves the account is managed by that Workspace domain.
     */
    public function testAllowlistedDomainRequiresMatchingHdClaim(): void
    {
        putenv('GOOGLE_AUTO_REGISTER=1');
        putenv('GOOGLE_AUTO_REGISTER_DOMAINS=apigoat.com');
        $p = GoogleRegistration::fromEnv();

        $this->assertTrue($p->allowsClaims(['email' => 'a@apigoat.com', 'hd' => 'apigoat.com']));
        $this->assertTrue($p->allowsClaims(['email' => 'a@ApiGoat.com', 'hd' => 'APIGOAT.com']));
        $this->assertFalse($p->allowsClaims(['email' => 'a@apigoat.com']), 'unmanaged account (no hd) refused');
        $this->assertFalse($p->allowsClaims(['email' => 'a@apigoat.com', 'hd' => 'evil.com']));
        $this->assertFalse($p->allowsClaims(['email' => 'a@evil.com', 'hd' => 'evil.com']));
    }

    public function testConsumerGmailAllowlistNeedsNoHd(): void
    {
        putenv('GOOGLE_AUTO_REGISTER=1');
        putenv('GOOGLE_AUTO_REGISTER_DOMAINS=gmail.com');
        $p = GoogleRegistration::fromEnv();
        $this->assertTrue($p->allowsClaims(['email' => 'a@gmail.com']));
        $this->assertFalse($p->allowsClaims(['email' => 'a@other.com']));
    }

    public function testNoAllowlistAllowsAnyVerifiedClaims(): void
    {
        putenv('GOOGLE_AUTO_REGISTER=1');
        $p = GoogleRegistration::fromEnv();
        $this->assertTrue($p->allowsClaims(['email' => 'a@whatever.org']));
        $this->assertFalse(GoogleRegistration::fromEnv()->allowsClaims([]));
    }

    public function testClaimsDisabledByDefault(): void
    {
        $this->assertFalse(GoogleRegistration::fromEnv()->allowsClaims(['email' => 'a@apigoat.com', 'hd' => 'apigoat.com']));
    }
}
