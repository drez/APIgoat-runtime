<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

// This repo carries no local vendor/phpunit install (it is a library
// consumed via a project's composer, e.g. vidifye's .admin/vendor/bin/phpunit).
// Explicitly require the source files under test — mirrors the convention
// used by the sibling PayPageReturnTest.php in this directory — so this
// test exercises THIS repo's copy regardless of what any consuming
// project has installed.
require_once __DIR__ . '/../../src/Stripe/StripeGateway.php';
require_once __DIR__ . '/../../src/Stripe/SubscriptionService.php';
require_once __DIR__ . '/support/FakeStripeHttpClient.php';
require_once __DIR__ . '/support/Fixtures.php';

use ApiGoat\Stripe\SubscriptionService;
use ApiGoat\Tests\Stripe\Support\FakeStripeHttpClient;
use ApiGoat\Tests\Stripe\Support\FakeSubRow;
use PHPUnit\Framework\TestCase;

final class SubscriptionServiceChangeImmediateTest extends TestCase
{
    protected function setUp(): void
    {
        \putenv('STRIPE_SECRET_KEY=sk_test_fake');
    }

    protected function tearDown(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        // Reset the SDK's global HTTP transport so other tests (or a real
        // run) fall back to the default CurlClient instead of our fake.
        \Stripe\ApiRequestor::setHttpClient(null);
    }

    public function testThrowsWhenStripeNotConfigured(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        $sub = new FakeSubRow('sub_123');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STRIPE_SECRET_KEY is not configured');
        SubscriptionService::changeImmediate($sub, 'price_new');
    }

    public function testSwapsItemToNewPriceWithAlwaysInvoiceProration(): void
    {
        $fake = new FakeStripeHttpClient([
            // 1) subscriptions->retrieve('sub_123')
            [
                'id'     => 'sub_123',
                'object' => 'subscription',
                'items'  => ['object' => 'list', 'data' => [
                    ['id' => 'si_item1', 'object' => 'subscription_item', 'price' => ['id' => 'price_old', 'object' => 'price']],
                ]],
            ],
            // 2) subscriptions->update('sub_123', [...])
            [
                'id'     => 'sub_123',
                'object' => 'subscription',
                'status' => 'active',
                'items'  => ['object' => 'list', 'data' => [
                    ['id' => 'si_item1', 'object' => 'subscription_item', 'price' => ['id' => 'price_new', 'object' => 'price']],
                ]],
            ],
        ]);
        \Stripe\ApiRequestor::setHttpClient($fake);

        $sub = new FakeSubRow('sub_123');
        $result = SubscriptionService::changeImmediate($sub, 'price_new');

        $this->assertInstanceOf(\Stripe\Subscription::class, $result);
        $this->assertSame('sub_123', $result->id);
        $this->assertSame('price_new', $result->items->data[0]->price->id);

        // First call: GET the subscription.
        $this->assertSame('get', $fake->calls[0]['method']);
        $this->assertStringContainsString('/v1/subscriptions/sub_123', $fake->calls[0]['url']);

        // Second call: POST the update with the exact item swap + proration behavior.
        $this->assertSame('post', $fake->calls[1]['method']);
        $this->assertStringContainsString('/v1/subscriptions/sub_123', $fake->calls[1]['url']);
        $params = $fake->calls[1]['params'];
        $this->assertSame('always_invoice', $params['proration_behavior']);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
        $this->assertSame('si_item1', $params['items'][0]['id']);
        $this->assertSame('price_new', $params['items'][0]['price']);
    }
}
