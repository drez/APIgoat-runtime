<?php

namespace ApiGoat\Tests\Apple;

require_once __DIR__ . '/../../src/Apple/TransactionRules.php';
require_once __DIR__ . '/../../src/Apple/AppleIap.php';

use ApiGoat\Apple\AppleIap;
use ApiGoat\Apple\TransactionRules as R;
use PHPUnit\Framework\TestCase;

final class TransactionRulesTest extends TestCase
{
    private const TOKEN = '3f2b9c1e-4d5a-5b6c-8d7e-1234567890ab';

    private function tx(array $over = []): array
    {
        return $over + [
            'bundleId' => 'com.example.app', 'environment' => 'Production', 'type' => R::CONSUMABLE,
            'appAccountToken' => self::TOKEN, 'transactionId' => '2000001', 'productId' => 'boost.30',
        ];
    }

    private function check(array $tx, bool $sandbox = true): void
    {
        R::assertClaimable($tx, R::CONSUMABLE, self::TOKEN, 'com.example.app', $sandbox);
    }

    public function testAcceptsAMatchingTransaction(): void
    {
        $this->check($this->tx());
        $this->check($this->tx(['appAccountToken' => \strtoupper(self::TOKEN)]));
        $this->addToAssertionCount(2);
    }

    /** @dataProvider refusals */
    public function testRefuses(array $over, bool $sandbox, string $reason): void
    {
        $this->expectExceptionMessage($reason);
        $this->check($this->tx($over), $sandbox);
    }

    public static function refusals(): array
    {
        return [
            'other app'            => [['bundleId' => 'com.evil.app'], true, 'bundle-mismatch'],
            'sandbox when refused' => [['environment' => 'Sandbox'], false, 'environment-refused'],
            'xcode env'            => [['environment' => 'Xcode'], true, 'environment-refused'],
            'wrong type'           => [['type' => R::SUBSCRIPTION], true, 'type-mismatch'],
            'someone else'         => [['appAccountToken' => '00000000-0000-5000-8000-000000000000'], true, 'account-mismatch'],
            'no token'             => [['appAccountToken' => null], true, 'account-mismatch'],
            'refunded'             => [['revocationDate' => 1700000000000], true, 'revoked'],
            'no product'           => [['productId' => ''], true, 'incomplete-transaction'],
        ];
    }

    public function testSandboxIsAcceptedByDefaultForTestFlightAndReview(): void
    {
        $this->check($this->tx(['environment' => 'Sandbox']));
        $this->addToAssertionCount(1);
    }

    public function testSubscriptionStatus(): void
    {
        $now = 1_800_000_000;
        $ms = fn (int $s) => $s * 1000;
        $this->assertSame('active', R::subscriptionStatus(['expiresDate' => $ms($now + 60)], null, $now));
        $this->assertSame('expired', R::subscriptionStatus(['expiresDate' => $ms($now - 60)], null, $now));
        $this->assertSame('grace', R::subscriptionStatus(['expiresDate' => $ms($now - 60)], ['gracePeriodExpiresDate' => $ms($now + 60)], $now));
        $this->assertSame('billing_retry', R::subscriptionStatus(['expiresDate' => $ms($now - 60)], ['isInBillingRetryPeriod' => true], $now));
        $this->assertSame('revoked', R::subscriptionStatus(['expiresDate' => $ms($now + 60), 'revocationDate' => 1], null, $now));
        $this->assertTrue(R::entitles('grace'));
        $this->assertFalse(R::entitles('billing_retry'));
    }

    public function testAccountTokenIsAStableUuidPerClient(): void
    {
        \putenv('APPLE_IAP_BUNDLE_ID=com.example.app');
        $a = AppleIap::accountToken('authy', 42);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
        $this->assertSame($a, AppleIap::accountToken('Authy', 42));
        $this->assertNotSame($a, AppleIap::accountToken('authy', 43));
        \putenv('APPLE_IAP_BUNDLE_ID');
    }
}
