<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

require_once __DIR__ . '/../../src/Stripe/WebhookEndpoint.php';

use ApiGoat\Stripe\WebhookEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * Stripe webhook concurrency + ordering:
 *  - two concurrent deliveries of one event both ran the handler (dedup only
 *    looked at the row status) → atomic claim on a processed_at lease;
 *  - events were applied in delivery order → an event older than one
 *    already applied to the same object is skipped.
 */
final class WebhookOrderingClaimTest extends TestCase
{
    private function ev(string $id, string $type, int $created, array $obj): array
    {
        return ['id' => $id, 'type' => $type, 'created' => $created, 'data' => ['object' => $obj]];
    }

    public function testOrderingKeyGroupsPaymentStateByIntent(): void
    {
        $this->assertSame('pi:pi_1', WebhookEndpoint::orderingKey($this->ev('e', 'payment_intent.succeeded', 1, ['id' => 'pi_1'])));
        $this->assertSame('pi:pi_1', WebhookEndpoint::orderingKey($this->ev('e', 'charge.refunded', 1, ['id' => 'ch_1', 'payment_intent' => 'pi_1'])));
        $this->assertSame('pi:pi_1', WebhookEndpoint::orderingKey($this->ev('e', 'checkout.session.completed', 1, ['id' => 'cs_1', 'payment_intent' => 'pi_1'])));
        $this->assertSame('sub:sub_1', WebhookEndpoint::orderingKey($this->ev('e', 'customer.subscription.updated', 1, ['id' => 'sub_1'])));
        $this->assertSame('dp:dp_1', WebhookEndpoint::orderingKey($this->ev('e', 'charge.dispute.closed', 1, ['id' => 'dp_1'])));
        // insert-only / unkeyed events are never ordered
        $this->assertNull(WebhookEndpoint::orderingKey($this->ev('e', 'invoice.paid', 1, ['id' => 'in_1'])));
        $this->assertNull(WebhookEndpoint::orderingKey($this->ev('e', 'checkout.session.completed', 1, ['id' => 'cs_1'])));
        $this->assertNull(WebhookEndpoint::orderingKey($this->ev('e', 'product.updated', 1, ['id' => 'prod_1'])));
    }

    public function testLateSucceededAfterRefundIsStale(): void
    {
        $refund = $this->ev('evt_r', 'charge.refunded', 200, ['id' => 'ch_1', 'payment_intent' => 'pi_1']);
        $late   = $this->ev('evt_s', 'payment_intent.succeeded', 100, ['id' => 'pi_1']);
        $this->assertTrue(WebhookEndpoint::supersedes($refund, $late), 'no paid-flag re-flip after a refund');
        $this->assertFalse(WebhookEndpoint::supersedes($late, $refund));
    }

    public function testOtherObjectsAndSameEventNeverSupersede(): void
    {
        $a = $this->ev('evt_a', 'customer.subscription.updated', 200, ['id' => 'sub_1']);
        $b = $this->ev('evt_b', 'customer.subscription.updated', 100, ['id' => 'sub_2']);
        $this->assertFalse(WebhookEndpoint::supersedes($a, $b), 'different subscription');
        $this->assertFalse(WebhookEndpoint::supersedes($a, $a), 'the event itself (a retry)');
    }

    public function testSameSecondSubscriptionLifecycleRank(): void
    {
        $created = $this->ev('evt_c', 'customer.subscription.created', 100, ['id' => 'sub_1', 'status' => 'incomplete']);
        $updated = $this->ev('evt_u', 'customer.subscription.updated', 100, ['id' => 'sub_1', 'status' => 'active']);
        $this->assertTrue(WebhookEndpoint::supersedes($updated, $created), 'created must not undo updated');
        $this->assertFalse(WebhookEndpoint::supersedes($created, $updated));
        // same second, unranked types: cannot order → applied
        $x = $this->ev('evt_x', 'payment_intent.succeeded', 100, ['id' => 'pi_1']);
        $y = $this->ev('evt_y', 'payment_intent.canceled', 100, ['id' => 'pi_1']);
        $this->assertFalse(WebhookEndpoint::supersedes($x, $y));
    }

    public function testLeaseCutoff(): void
    {
        $now = 1_800_000_000;
        $cut = WebhookEndpoint::leaseCutoff($now);
        // processed_at > cutoff is claimable
        $this->assertTrue(0 > $cut, 'a real processed_at (positive) is past the cutoff');
        $this->assertFalse(-$now > $cut, 'a lease taken now is held');
        $this->assertFalse(-($now - WebhookEndpoint::CLAIM_LEASE_SECONDS + 1) > $cut, 'a lease inside the window is held');
        $this->assertTrue(-($now - WebhookEndpoint::CLAIM_LEASE_SECONDS - 1) > $cut, 'an expired lease may be re-taken');
    }

    public function testClaimIsOneConditionalUpdate(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Stripe/WebhookEndpoint.php');
        $this->assertStringContainsString("->filterByStatus(['received', 'failed'])", $src);
        $this->assertStringContainsString("->update(['ProcessedAt' => -\$now]);", $src);
        $this->assertStringContainsString("return (int) \$affected === 1;", $src);
        $this->assertStringContainsString("'Event is being processed'", $src);
        // the failure path's setProcessedAt(null) must be a real change
        $this->assertStringContainsString("\$row->setProcessedAt(-\$now);", $src);
    }
}
