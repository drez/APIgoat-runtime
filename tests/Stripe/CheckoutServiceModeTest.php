<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

// This repo carries no local vendor/phpunit install (it is a library
// consumed via a project's composer, e.g. vidifye's .admin/vendor/bin/phpunit).
// Explicitly require the source files under test — mirrors the convention
// used by the sibling PayPageReturnTest.php in this directory — so this
// test exercises THIS repo's copy regardless of what any consuming
// project has installed.
require_once __DIR__ . '/../../src/Stripe/StripeDb.php';
require_once __DIR__ . '/../../src/Stripe/CheckoutService.php';
require_once __DIR__ . '/support/FakeStripePriceDb.php';

use ApiGoat\Stripe\CheckoutService;
use App\FakeCheckoutCustomer;
use App\FakePayableRecord;
use App\StripePrice;
use App\StripePriceQuery;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the CheckoutService::buildSessionParams mode
 * derivation (2026-07-28 fix-wave finding 1): a $opts['price_id'] catalog
 * opt used to force Checkout 'subscription' mode unconditionally, which
 * made Stripe reject one_time catalog prices (boost + video-extend
 * packages) with "You must provide at least one recurring price in
 * subscription mode". Mode must now come from the resolved StripePrice
 * row's own `type`.
 *
 * buildSessionParams is private static — invoked via reflection so this
 * test exercises the real shared code path (also used by createForRecord
 * and refreshSessionFor) without needing a live Stripe gateway, a Propel
 * database, or the full StripeManifest/customer-resolution machinery.
 */
final class CheckoutServiceModeTest extends TestCase
{
    protected function setUp(): void
    {
        StripePriceQuery::reset();
    }

    protected function tearDown(): void
    {
        StripePriceQuery::reset();
    }

    /** @return array{params: array, mode: string, amount: int, currency: string} */
    private function build(array $entry, array $opts): array
    {
        $rec      = new FakePayableRecord(9.99, 'usd', 501);
        $customer = new FakeCheckoutCustomer('cus_fake123');

        $method = new \ReflectionMethod(CheckoutService::class, 'buildSessionParams');
        $method->setAccessible(true);

        /** @var array{params: array, mode: string, amount: int, currency: string} $result */
        $result = $method->invoke(null, $rec, $entry, 'product_boost', $customer, $opts, 'rawtoken-abc');
        return $result;
    }

    private function entry(): array
    {
        return [
            'entity'             => 'ProductBoost',
            'amount_getter'      => 'getAmount',
            'currency'           => null,
            'currency_getter'    => 'getCurrency',
            'description_getter' => null,
            'paid_flag_setter'   => 'setIsActive',
            'client_table'       => 'authy',
            'client_entity'      => 'Authy',
            'client_id_getter'   => 'getIdAuthy',
        ];
    }

    public function testOneTimeCatalogPriceUsesPaymentModeWithCatalogLineItem(): void
    {
        StripePriceQuery::register(new StripePrice(42, 'one_time', 'price_onetime_42'));

        $result = $this->build($this->entry(), ['price_id' => 42]);

        $this->assertSame('payment', $result['mode']);
        $this->assertSame('payment', $result['params']['mode']);
        $this->assertSame(
            [['quantity' => 1, 'price' => 'price_onetime_42']],
            $result['params']['line_items']
        );
        // Payment mode still stamps payment_intent_data metadata (audit M2:
        // no setup_future_usage / card vaulting) — unchanged behavior.
        $this->assertArrayHasKey('payment_intent_data', $result['params']);
        $this->assertSame($result['params']['metadata'], $result['params']['payment_intent_data']['metadata']);
    }

    public function testRecurringCatalogPriceKeepsSubscriptionModeUnchanged(): void
    {
        StripePriceQuery::register(new StripePrice(7, 'recurring', 'price_recurring_7'));

        $result = $this->build($this->entry(), ['price_id' => 7]);

        $this->assertSame('subscription', $result['mode']);
        $this->assertSame('subscription', $result['params']['mode']);
        $this->assertSame(
            [['quantity' => 1, 'price' => 'price_recurring_7']],
            $result['params']['line_items']
        );
        // Subscription-mode sessions never get payment_intent_data.
        $this->assertArrayNotHasKey('payment_intent_data', $result['params']);
    }

    public function testUnresolvablePriceIdKeepsSubscriptionModeAssumption(): void
    {
        // No row registered for id 999 — mirrors "no row is resolvable, keep
        // current behavior" from the fix spec. Reaches the final catalog
        // lookup in subscription mode, which throws because the (fake) row
        // is genuinely absent — same failure shape as before this fix.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Price not found or not pushed to Stripe');

        $this->build($this->entry(), ['price_id' => 999]);
    }

    public function testRawStripePriceIdFallbackForOneTimeRowUsesPaymentMode(): void
    {
        // refreshSessionFor's fallback shape: price_id present-but-null +
        // stripe_price_id carrying the raw id. If a local row for that raw
        // id DOES resolve and is one_time, mode must be payment (not the
        // historical subscription assumption, which is reserved for when no
        // row is resolvable at all).
        StripePriceQuery::register(new StripePrice(11, 'one_time', 'price_raw_11'));

        $result = $this->build($this->entry(), ['price_id' => null, 'stripe_price_id' => 'price_raw_11']);

        $this->assertSame('payment', $result['mode']);
        $this->assertSame(
            [['quantity' => 1, 'price' => 'price_raw_11']],
            $result['params']['line_items']
        );
    }

    public function testRawStripePriceIdFallbackWithNoLocalRowKeepsSubscriptionMode(): void
    {
        // No row registered — the genuine refreshSessionFor fallback case
        // (original subscription price managed directly in Stripe, no local
        // StripePrice row at all). Must stay byte-identical: subscription
        // mode, line item built straight from the raw id.
        $result = $this->build($this->entry(), ['price_id' => null, 'stripe_price_id' => 'price_managed_in_stripe_only']);

        $this->assertSame('subscription', $result['mode']);
        $this->assertSame(
            [['quantity' => 1, 'price' => 'price_managed_in_stripe_only']],
            $result['params']['line_items']
        );
        $this->assertArrayNotHasKey('payment_intent_data', $result['params']);
    }
}
