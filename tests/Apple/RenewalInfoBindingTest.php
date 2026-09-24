<?php

declare(strict_types=1);

namespace App {
    if (!\class_exists(\App\AppleSubscription::class, false)) {
        require_once __DIR__ . '/../Stripe/support/FakeLedgerDb.php';

        class AppleSubscription extends \ApiGoat\Tests\Stripe\Support\FakeRow
        {
        }
        class AppleSubscriptionQuery extends \ApiGoat\Tests\Stripe\Support\FakeQuery
        {
        }
    }
}

namespace ApiGoat\Tests\Apple {

    require_once __DIR__ . '/../../src/Apple/AppleIap.php';
    require_once __DIR__ . '/../../src/Apple/TransactionRules.php';
    require_once __DIR__ . '/../../src/Apple/SignedDataVerifier.php';
    require_once __DIR__ . '/../../src/Apple/PurchaseService.php';
    require_once __DIR__ . '/../Stripe/support/FakeLedgerDb.php';

    use ApiGoat\Apple\PurchaseService;
    use ApiGoat\Apple\TransactionRules as R;
    use ApiGoat\Tests\Stripe\Support\FakeStore;
    use PHPUnit\Framework\TestCase;

    /**
     * SECURITY: Apple-signed renewal info is only trusted for the subscription
     * it describes — pairing an expired subscription with some other
     * subscription's grace-period renewal info must not grant 'grace'.
     */
    final class RenewalInfoBindingTest extends TestCase
    {
        protected function setUp(): void
        {
            FakeStore::reset();
        }

        protected function tearDown(): void
        {
            FakeStore::reset();
        }

        private function expiredTx(): array
        {
            return [
                'transactionId' => '3000005', 'originalTransactionId' => '3000001', 'productId' => 'pro.monthly',
                'bundleId' => 'com.example.app', 'environment' => 'Production', 'type' => R::SUBSCRIPTION,
                'expiresDate' => (\time() - 3600) * 1000,
            ];
        }

        private function graceRenewal(array $over = []): array
        {
            return $over + [
                'originalTransactionId' => '3000001', 'environment' => 'Production',
                'gracePeriodExpiresDate' => (\time() + 86400) * 1000, 'autoRenewStatus' => 1,
            ];
        }

        public function testBoundRenewalIsKept(): void
        {
            $r = $this->graceRenewal();
            $this->assertSame($r, R::boundRenewal($this->expiredTx(), $r));
            $this->assertNull(R::boundRenewal($this->expiredTx(), null));
        }

        /** @dataProvider foreignRenewals */
        public function testForeignRenewalIsIgnored(array $over): void
        {
            $this->assertNull(R::boundRenewal($this->expiredTx(), $this->graceRenewal($over)));
        }

        public static function foreignRenewals(): array
        {
            return [
                'other subscription' => [['originalTransactionId' => '9999999']],
                'missing orig id'    => [['originalTransactionId' => null]],
                'other environment'  => [['environment' => 'Sandbox']],
                'other app'          => [['bundleId' => 'com.evil.app']],
            ];
        }

        public function testTxWithoutOriginalIdTrustsNoRenewal(): void
        {
            $tx = $this->expiredTx();
            unset($tx['originalTransactionId']);
            $this->assertNull(R::boundRenewal($tx, $this->graceRenewal(['originalTransactionId' => ''])));
        }

        private function apply(array $renewal): object
        {
            $svc = (new \ReflectionClass(PurchaseService::class))->newInstanceWithoutConstructor();
            return $svc->applySubscription($this->expiredTx(), $renewal, 'gc_client', 42);
        }

        public function testForeignGraceRenewalDoesNotEntitle(): void
        {
            $sub = $this->apply($this->graceRenewal(['originalTransactionId' => '9999999', 'autoRenewStatus' => 1]));
            $this->assertSame('expired', $sub->getStatus());
            $this->assertNull($sub->getAutoRenew(), 'foreign renewal info must not be applied at all');
        }

        public function testOwnGraceRenewalStillEntitles(): void
        {
            $sub = $this->apply($this->graceRenewal());
            $this->assertSame('grace', $sub->getStatus());
            $this->assertSame(1, $sub->getAutoRenew());
        }
    }
}
