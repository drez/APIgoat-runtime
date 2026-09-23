<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Apple;

require_once __DIR__ . '/../../src/Apple/AppleIap.php';
require_once __DIR__ . '/../../src/Apple/TransactionRules.php';
require_once __DIR__ . '/../../src/Apple/SignedDataVerifier.php';
require_once __DIR__ . '/../../src/Apple/PurchaseService.php';
require_once __DIR__ . '/../../src/Apple/NotificationEndpoint.php';
require_once __DIR__ . '/../Stripe/support/FakeLedgerDb.php';

use ApiGoat\Apple\NotificationEndpoint;
use ApiGoat\Apple\PurchaseService;
use ApiGoat\Tests\Stripe\Support\FakeStore;
use App\AppleEvent;
use App\AppleTransaction;
use PHPUnit\Framework\TestCase;

/**
 * review-3 #6 — Apple:
 *  - an Apple-signed JWS stays valid after a refund, so a re-posted copy is
 *    refused when the ledger already records that purchase refunded/revoked;
 *  - one notificationUUID is claimed by exactly one delivery (a REFUND
 *    listener runs once), with the same processed_at lease as Stripe's.
 */
final class RefundReplayAndNotificationClaimTest extends TestCase
{
    protected function setUp(): void
    {
        FakeStore::reset();
    }

    protected function tearDown(): void
    {
        FakeStore::reset();
    }

    private function ledger(string $txId, string $origId, string $status): void
    {
        $r = new AppleTransaction();
        $r->d = ['TransactionId' => $txId, 'OriginalTransactionId' => $origId, 'Status' => $status];
        $r->save();
    }

    private function assertNotRevoked(array $tx, bool $consumable): void
    {
        $svc = (new \ReflectionClass(PurchaseService::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(PurchaseService::class, 'assertNotRevoked');
        $m->setAccessible(true);
        $m->invoke($svc, $tx, $consumable);
    }

    public function testRefundedConsumableJwsIsRefused(): void
    {
        $this->ledger('2000001', '2000001', 'refunded');
        $this->expectExceptionMessage('revoked');
        $this->assertNotRevoked(['transactionId' => '2000001', 'originalTransactionId' => '2000001'], true);
    }

    public function testRevokedOriginalRefusesAConsumable(): void
    {
        $this->ledger('2000009', '2000001', 'revoked');
        $this->expectExceptionMessage('revoked');
        $this->assertNotRevoked(['transactionId' => '2000002', 'originalTransactionId' => '2000001'], true);
    }

    public function testRefundedSubscriptionTransactionIsRefused(): void
    {
        $this->ledger('3000005', '3000001', 'refunded');
        $this->expectExceptionMessage('revoked');
        $this->assertNotRevoked(['transactionId' => '3000005', 'originalTransactionId' => '3000001'], false);
    }

    public function testRefundedPastPeriodDoesNotBlockALaterRenewal(): void
    {
        $this->ledger('3000005', '3000001', 'refunded');
        $this->assertNotRevoked(['transactionId' => '3000006', 'originalTransactionId' => '3000001'], false);
        $this->addToAssertionCount(1);
    }

    public function testVerifiedLedgerRowIsNotRefused(): void
    {
        $this->ledger('2000001', '2000001', 'verified');
        $this->assertNotRevoked(['transactionId' => '2000001', 'originalTransactionId' => '2000001'], true);
        $this->addToAssertionCount(1);
    }

    public function testClaimPathsRunTheRefundCheck(): void
    {
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Apple/PurchaseService.php');
        $this->assertSame(2, \substr_count($src, '$this->assertNotRevoked($tx, '));
        // The consumable check precedes the replay short-circuit.
        $claim = \substr($src, \strpos($src, 'public function claimConsumable'), 1500);
        $this->assertLessThan(\strpos($claim, '$existing = $this->ledgerRow('), \strpos($claim, '$this->assertNotRevoked($tx, true);'));
    }

    private static function n(string $uuid = 'uuid-1'): array
    {
        return ['notificationUUID' => $uuid, 'notificationType' => 'REFUND', 'subtype' => ''];
    }

    public function testDuplicateNotificationIsClaimedOnce(): void
    {
        $now = 1_800_000_000;
        $first = NotificationEndpoint::claimEvent('uuid-1', self::n(), 'jws', $now);
        $this->assertInstanceOf(AppleEvent::class, $first);
        $this->assertSame(-$now, $first->getProcessedAt());

        $this->assertSame('busy', NotificationEndpoint::claimEvent('uuid-1', self::n(), 'jws', $now + 1),
            'a concurrent delivery must not run the handler');
        $this->assertCount(1, FakeStore::all(AppleEvent::class));

        $first->setStatus('processed');
        $first->setProcessedAt($now + 2);
        $first->save();
        $this->assertSame('done', NotificationEndpoint::claimEvent('uuid-1', self::n(), 'jws', $now + 3));
    }

    public function testExpiredLeaseAndFailedRowCanBeReclaimed(): void
    {
        $now = 1_800_000_000;
        $row = NotificationEndpoint::claimEvent('uuid-2', self::n('uuid-2'), 'jws', $now);
        $this->assertIsObject($row);
        // Handler died holding the lease: re-takeable once the lease expires.
        $this->assertSame('busy', NotificationEndpoint::claimEvent('uuid-2', self::n('uuid-2'), 'jws', $now + 10));
        $again = NotificationEndpoint::claimEvent('uuid-2', self::n('uuid-2'), 'jws', $now + NotificationEndpoint::CLAIM_LEASE_SECONDS + 1);
        $this->assertIsObject($again);

        // The failure path releases the lease (processed_at NULL): claimable at once.
        $again->setStatus('failed');
        $again->setProcessedAt(null);
        $again->save();
        $this->assertIsObject(NotificationEndpoint::claimEvent('uuid-2', self::n('uuid-2'), 'jws', $now + 400));
    }

    public function testHandleUsesTheClaimAndReleasesOnFailure(): void
    {
        $src = (string) \file_get_contents(__DIR__ . '/../../src/Apple/NotificationEndpoint.php');
        $handle = \substr($src, \strpos($src, 'public static function handle('), 2500);
        $this->assertStringContainsString('self::claimEvent($uuid, $n, $jws, \time())', $handle);
        $this->assertStringContainsString("'Notification is being processed'", $handle);
        $this->assertStringContainsString('$row->setProcessedAt(null);', $handle);
    }
}
