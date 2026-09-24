<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

require_once __DIR__ . '/../../src/Stripe/StripeDb.php';
require_once __DIR__ . '/../../src/Stripe/StripeGateway.php';
require_once __DIR__ . '/../../src/Stripe/CheckoutService.php';
require_once __DIR__ . '/support/FakeStripeHttpClient.php';
require_once __DIR__ . '/support/FakeStripePriceDb.php';
require_once __DIR__ . '/support/FakeLedgerDb.php';

use ApiGoat\Stripe\CheckoutService;
use ApiGoat\Stripe\StripeGateway;
use ApiGoat\Tests\Stripe\Support\FakeStripeHttpClient;
use ApiGoat\Tests\Stripe\Support\FakeStore;
use App\FakeCheckoutCustomer;
use App\FakePayableRecord;
use App\StripePayment;
use App\StripePrice;
use App\StripePriceQuery;
use PHPUnit\Framework\TestCase;

/**
 * review-3 #6 — Checkout:
 *  - a new checkout expires the payable's other open sessions (their rows go
 *    canceled, so the old pay links stop working);
 *  - a client-supplied price is accepted only for an active, recurring,
 *    pushed catalog row;
 *  - a one-time catalog package records the CATALOG amount as owed (what the
 *    session collects), so the webhook's amount check compares like with like.
 */
final class CheckoutSupersedeAndPriceTest extends TestCase
{
    protected function setUp(): void
    {
        FakeStore::reset();
        StripePriceQuery::reset();
        \putenv('STRIPE_SECRET_KEY=sk_test_fake');
    }

    protected function tearDown(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        \Stripe\ApiRequestor::setHttpClient(null);
        FakeStore::reset();
        StripePriceQuery::reset();
    }

    private function row(array $d): StripePayment
    {
        $r = new StripePayment();
        $r->d = $d + ['PayableTable' => 'invoice', 'PayableId' => 7];
        $r->save();
        return $r;
    }

    public function testNewCheckoutExpiresTheOpenSessionsOfTheSamePayable(): void
    {
        $open    = $this->row(['Status' => 'pending', 'StripeCheckoutSessionId' => 'cs_open']);
        $gone    = $this->row(['Status' => 'pending', 'StripeCheckoutSessionId' => 'cs_gone']);
        $paid    = $this->row(['Status' => 'succeeded', 'StripeCheckoutSessionId' => 'cs_paid']);
        $other   = $this->row(['Status' => 'pending', 'StripeCheckoutSessionId' => 'cs_other', 'PayableId' => 8]);
        $noSess  = $this->row(['Status' => 'pending']);

        $fake = new class ([
            ['id' => 'cs_open', 'object' => 'checkout.session', 'status' => 'expired'],
        ]) implements \Stripe\HttpClient\ClientInterface {
            public array $urls = [];
            public function __construct(private array $responses)
            {
            }
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->urls[] = $method . ' ' . $absUrl;
                if (\str_contains($absUrl, 'cs_gone')) {
                    // Stripe refuses: the session is no longer open.
                    return [(string) \json_encode(['error' => ['message' => 'Only open sessions can be expired', 'type' => 'invalid_request_error']]), 400, []];
                }
                return [(string) \json_encode(\array_shift($this->responses)), 200, []];
            }
        };
        \Stripe\ApiRequestor::setHttpClient($fake);

        $n = CheckoutService::expireOpenSessionsFor('invoice', 7, StripeGateway::fromEnv());

        $this->assertSame(1, $n);
        $this->assertSame('canceled', $open->getStatus());
        $this->assertSame('pending', $gone->getStatus(), 'a session Stripe will not expire is left alone');
        $this->assertSame('succeeded', $paid->getStatus());
        $this->assertSame('pending', $other->getStatus(), 'another payable is untouched');
        $this->assertSame('pending', $noSess->getStatus());
        $this->assertCount(2, $fake->urls);
        $this->assertStringContainsString('/v1/checkout/sessions/cs_open/expire', $fake->urls[0]);
    }

    public function testCreateForRecordExpiresBeforeCreating(): void
    {
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Stripe/CheckoutService.php');
        $create = \substr($src, \strpos($src, 'public static function createForRecord'), 1500);
        $this->assertLessThan(
            \strpos($create, 'checkout->sessions->create('),
            \strpos($create, 'self::expireOpenSessionsFor($table, (int) $rec->getPrimaryKey(), $gw);')
        );
    }

    public function testClientPriceMustBeAnActiveRecurringPushedPlan(): void
    {
        StripePriceQuery::register(new StripePrice(1, 'recurring', 'price_plan'));
        StripePriceQuery::register(new StripePrice(2, 'one_time', 'price_boost'));
        StripePriceQuery::register(new StripePrice(3, 'recurring', 'price_old', 999, 'usd', false));
        StripePriceQuery::register(new StripePrice(4, 'recurring', ''));

        $this->assertSame(1, CheckoutService::clientSubscriptionPriceId('1'));
        foreach (['2' => 'one_time', '3' => 'inactive', '4' => 'not pushed', '99' => 'unknown', 'x' => 'garbage', '' => 'empty'] as $id => $why) {
            try {
                CheckoutService::clientSubscriptionPriceId($id);
                $this->fail("price {$id} ({$why}) must be refused");
            } catch (\RuntimeException $e) {
                $this->assertSame('This plan is not available', $e->getMessage());
            }
        }
    }

    public function testOneTimeCatalogPackageRecordsTheCatalogAmount(): void
    {
        StripePriceQuery::register(new StripePrice(42, 'one_time', 'price_boost', 1500, 'CAD'));
        $m = new \ReflectionMethod(CheckoutService::class, 'buildSessionParams');
        $m->setAccessible(true);
        $entry = ['entity' => 'ProductBoost', 'amount_getter' => 'getAmount', 'currency' => null, 'currency_getter' => 'getCurrency',
            'description_getter' => null];
        $built = $m->invoke(null, new FakePayableRecord(9.99, 'usd', 5), $entry, 'product_boost', new FakeCheckoutCustomer('cus_1'), ['price_id' => 42], 'tok');
        $this->assertSame('payment', $built['mode']);
        $this->assertSame(1500, $built['amount']);
        $this->assertSame('cad', $built['currency']);

        // Record-priced one-time payment: unchanged, the record's figure.
        $built = $m->invoke(null, new FakePayableRecord(9.99, 'usd', 5), $entry, 'product_boost', new FakeCheckoutCustomer('cus_1'), [], 'tok');
        $this->assertSame(999, $built['amount']);
        $this->assertSame('usd', $built['currency']);
    }

    public function testPayPageRefusesASupersededLink(): void
    {
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Stripe/PayPage.php');
        $start  = \strpos($src, 'public static function render(');
        $next   = \strpos($src, "\n    public ", $start + 1);
        $render = \substr($src, $start, $next === false ? null : $next - $start);
        $this->assertNotFalse(\strpos($render, 'refreshSessionFor'), 'render() still regenerates expired sessions');
        $this->assertStringContainsString("(string) \$pay->getStatus() === 'canceled'", $render);
        $this->assertLessThan(\strpos($render, 'refreshSessionFor'), \strpos($render, "=== 'canceled'"),
            'a canceled row is refused before any session is regenerated');
    }
}
