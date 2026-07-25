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

final class SubscriptionServiceScheduleDowngradeTest extends TestCase
{
    protected function setUp(): void
    {
        \putenv('STRIPE_SECRET_KEY=sk_test_fake');
    }

    protected function tearDown(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        \Stripe\ApiRequestor::setHttpClient(null);
    }

    public function testThrowsWhenStripeNotConfigured(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        $sub = new FakeSubRow('sub_123');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STRIPE_SECRET_KEY is not configured');
        SubscriptionService::scheduleDowngrade($sub, 'price_cheap');
    }

    public function testCreatesTwoPhaseScheduleAtPeriodEnd(): void
    {
        $periodStart = 1_700_000_000;
        $periodEnd   = 1_702_678_400; // ~31 days later

        $fake = new FakeStripeHttpClient([
            // 1) subscriptions->retrieve('sub_123')
            [
                'id' => 'sub_123', 'object' => 'subscription',
                'current_period_start' => $periodStart,
                'current_period_end'   => $periodEnd,
                'items' => ['object' => 'list', 'data' => [
                    ['id' => 'si_item1', 'object' => 'subscription_item', 'price' => ['id' => 'price_current', 'object' => 'price']],
                ]],
            ],
            // 2) subscriptionSchedules->create(['from_subscription' => 'sub_123'])
            ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'status' => 'active'],
            // 3) subscriptionSchedules->update('sub_sched_1', [...])
            [
                'id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'status' => 'active',
                'end_behavior' => 'release',
            ],
        ]);
        \Stripe\ApiRequestor::setHttpClient($fake);

        $sub = new FakeSubRow('sub_123');
        $result = SubscriptionService::scheduleDowngrade($sub, 'price_cheap');

        $this->assertInstanceOf(\Stripe\SubscriptionSchedule::class, $result);
        $this->assertSame('sub_sched_1', $result->id);
        $this->assertSame('release', $result->end_behavior);

        $this->assertSame('get', $fake->calls[0]['method']);
        $this->assertStringContainsString('/v1/subscriptions/sub_123', $fake->calls[0]['url']);

        $this->assertSame('post', $fake->calls[1]['method']);
        $this->assertStringContainsString('/v1/subscription_schedules', $fake->calls[1]['url']);
        $this->assertSame('sub_123', $fake->calls[1]['params']['from_subscription']);

        $this->assertSame('post', $fake->calls[2]['method']);
        $this->assertStringContainsString('/v1/subscription_schedules/sub_sched_1', $fake->calls[2]['url']);
        $params = $fake->calls[2]['params'];
        $this->assertSame('release', $params['end_behavior']);
        $this->assertCount(2, $params['phases']);

        $phase1 = $params['phases'][0];
        $this->assertSame('price_current', $phase1['items'][0]['price']);
        $this->assertSame($periodStart, $phase1['start_date']);
        $this->assertSame($periodEnd, $phase1['end_date']);

        $phase2 = $params['phases'][1];
        $this->assertSame('price_cheap', $phase2['items'][0]['price']);
        $this->assertSame($periodEnd, $phase2['start_date']);
        $this->assertArrayNotHasKey('end_date', $phase2);
    }
}
