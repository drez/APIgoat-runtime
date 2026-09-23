<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

require_once __DIR__ . '/../../src/Stripe/StripeDb.php';
require_once __DIR__ . '/../../src/Stripe/StripeManifest.php';
require_once __DIR__ . '/../../src/Stripe/StripeGateway.php';
require_once __DIR__ . '/../../src/Stripe/WebhookHandler.php';
require_once __DIR__ . '/support/FakeLedgerDb.php';

use ApiGoat\Stripe\StripeManifest;
use ApiGoat\Stripe\WebhookHandler;
use ApiGoat\Tests\Stripe\Support\FakeStore;
use App\GcPayable;
use App\StripePayment;
use PHPUnit\Framework\TestCase;

/**
 * review-3 #6: a paid Stripe object marks the payable paid only when the
 * collected amount + currency equal what the ledger row recorded as owed,
 * and only 0 → 1 through a conditional UPDATE (a replay, a second paid
 * session or a consumed grant never re-grants).
 */
final class WebhookPaidFlagTest extends TestCase
{
    protected function setUp(): void
    {
        FakeStore::reset();
        \putenv('STRIPE_SECRET_KEY');   // captureDefaultMethod / retrieveCharge stay offline
        StripeManifest::reset(['payables' => ['gc_payable' => [
            'entity' => 'GcPayable', 'paid_flag_setter' => 'setIsPaid',
            'amount_getter' => 'getAmount', 'currency' => null, 'currency_getter' => 'getCurrency',
            'description_getter' => null, 'client_table' => 'gc_client', 'client_entity' => 'GcClient',
            'client_id_getter' => 'getIdGcClient', 'modes' => ['payment', 'subscription'],
        ]]]);
    }

    protected function tearDown(): void
    {
        StripeManifest::reset();
        FakeStore::reset();
    }

    private function payable(int $flag = 0): GcPayable
    {
        $p = new GcPayable();
        $p->setIsPaid($flag);
        $p->save();
        return $p;
    }

    private function ledger(GcPayable $p, int $amount = 5000, string $currency = 'usd', string $session = 'cs_1', ?string $intent = null): StripePayment
    {
        $pay = new StripePayment();
        $pay->setPayableTable('gc_payable');
        $pay->setPayableId($p->getPrimaryKey());
        $pay->setAmount($amount);
        $pay->setCurrency($currency);
        $pay->setStatus('pending');
        $pay->setStripeCheckoutSessionId($session);
        if ($intent !== null) {
            $pay->setStripePaymentIntentId($intent);
        }
        $pay->save();
        return $pay;
    }

    private function completed(array $over = []): void
    {
        WebhookHandler::process(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $over + [
            'id' => 'cs_1', 'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 5000, 'currency' => 'usd',
        ]]]);
    }

    public function testMatchingAmountMarksPaid(): void
    {
        $p = $this->payable();
        $pay = $this->ledger($p);
        $this->completed();
        $this->assertSame(1, $p->getIsPaid());
        $this->assertSame('succeeded', $pay->getStatus());
        $this->assertNull($pay->getErrorMessage());
    }

    public function testAmountMismatchDoesNotMarkPaid(): void
    {
        $p = $this->payable();
        $pay = $this->ledger($p);
        $this->completed(['amount_total' => 100]);
        $this->assertSame(0, $p->getIsPaid(), 'underpaid session must not grant');
        $this->assertSame('succeeded', $pay->getStatus(), 'the ledger still records the money Stripe took');
        $this->assertStringContainsString('Not marked paid: amount 100 != owed 5000', (string) $pay->getErrorMessage());
    }

    public function testCurrencyMismatchDoesNotMarkPaid(): void
    {
        $p = $this->payable();
        $pay = $this->ledger($p);
        $this->completed(['currency' => 'jpy']);
        $this->assertSame(0, $p->getIsPaid());
        $this->assertStringContainsString('currency jpy != owed usd', (string) $pay->getErrorMessage());
    }

    public function testMissingAmountDoesNotMarkPaid(): void
    {
        $p = $this->payable();
        $this->ledger($p);
        $this->completed(['amount_total' => null]);
        $this->assertSame(0, $p->getIsPaid());
    }

    public function testSubscriptionSessionSkipsTheAmountCheck(): void
    {
        // A trial / coupon makes amount_total 0: the plan is server-selected.
        $p = $this->payable();
        $this->ledger($p, 0);
        $this->completed(['mode' => 'subscription', 'amount_total' => 0]);
        $this->assertSame(1, $p->getIsPaid());
    }

    public function testFlipIsZeroToOneOnly(): void
    {
        $p = $this->payable();
        $pay = $this->ledger($p);
        $this->assertTrue(WebhookHandler::flipPaidFlag($pay), 'first claim wins');
        $this->assertFalse(WebhookHandler::flipPaidFlag($pay), 'second claim affects no row');
        $this->assertSame(1, $p->getIsPaid());

        // A consumed grant (2, the reconcilers' state) is never reset to 1.
        $consumed = $this->payable(2);
        $this->assertFalse(WebhookHandler::flipPaidFlag($this->ledger($consumed, 5000, 'usd', 'cs_2')));
        $this->assertSame(2, $consumed->getIsPaid());

        // NULL counts as unpaid.
        $n = $this->payable();
        $n->setIsPaid(null);
        $this->assertTrue(WebhookHandler::flipPaidFlag($this->ledger($n, 5000, 'usd', 'cs_3')));
        $this->assertSame(1, $n->getIsPaid());
    }

    public function testIntentSucceededChecksAmountReceived(): void
    {
        $p = $this->payable();
        $pay = $this->ledger($p, 5000, 'usd', 'cs_9', 'pi_9');
        WebhookHandler::process(['id' => 'evt_2', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_9', 'amount' => 5000, 'amount_received' => 4999, 'currency' => 'usd',
        ]]]);
        $this->assertSame(0, $p->getIsPaid());
        $this->assertStringContainsString('amount 4999 != owed 5000', (string) $pay->getErrorMessage());

        WebhookHandler::process(['id' => 'evt_3', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_9', 'amount' => 5000, 'amount_received' => 5000, 'currency' => 'USD',
        ]]]);
        $this->assertSame(1, $p->getIsPaid());
    }

    public function testRenewalLedgerRowNeverFlipsAPayable(): void
    {
        $pay = new StripePayment();
        $pay->setPayableTable('gc_payable');
        $pay->setPayableId(0);
        $this->assertFalse(WebhookHandler::flipPaidFlag($pay));
    }
}
