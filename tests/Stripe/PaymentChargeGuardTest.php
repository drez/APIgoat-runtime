<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

require_once __DIR__ . '/../../src/Stripe/StripeDb.php';
require_once __DIR__ . '/../../src/Stripe/StripeManifest.php';
require_once __DIR__ . '/../../src/Stripe/StripeGateway.php';
require_once __DIR__ . '/../../src/Stripe/WebhookHandler.php';
require_once __DIR__ . '/../../src/Stripe/CheckoutService.php';
require_once __DIR__ . '/../../src/Stripe/PaymentService.php';
require_once __DIR__ . '/support/FakeLedgerDb.php';

use ApiGoat\Stripe\PaymentService;
use ApiGoat\Stripe\StripeManifest;
use ApiGoat\Tests\Stripe\Support\FakeStore;
use ApiGoat\Utility\MicroCache;
use App\GcClient;
use App\GcPayable;
use App\StripeCustomer;
use App\StripePayment;
use PHPUnit\Framework\TestCase;

/** Stripe HTTP double that also records request headers (idempotency key). */
final class RecordingStripeHttp implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<int, array{method:string, url:string, headers:array, params:array}> */
    public array $calls = [];

    /** @param array<int, array<string, mixed>> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params ?: []];
        $body = \array_shift($this->responses);
        if ($body === null) {
            throw new \RuntimeException("unexpected request {$method} {$absUrl}");
        }
        return [(string) \json_encode($body), 200, []];
    }

    public function idempotencyKey(int $i): ?string
    {
        foreach ($this->calls[$i]['headers'] ?? [] as $h) {
            if (\stripos((string) $h, 'Idempotency-Key:') === 0) {
                return \trim(\substr((string) $h, \strlen('Idempotency-Key:')));
            }
        }
        return null;
    }
}

/**
 * review-3 #6 — saved-card charge: a double click (or two tabs) must not
 * charge twice; the Stripe idempotency key is deterministic per payable,
 * amount and attempt; a polled 3-D Secure / processing intent is pending,
 * never failed.
 */
final class PaymentChargeGuardTest extends TestCase
{
    private GcPayable $payable;

    protected function setUp(): void
    {
        FakeStore::reset();
        MicroCache::flushLocal();
        \putenv('STRIPE_SECRET_KEY=sk_test_fake');
        StripeManifest::reset(['payables' => ['gc_payable' => [
            'entity' => 'GcPayable', 'paid_flag_setter' => 'setIsPaid',
            'amount_getter' => 'getAmount', 'currency' => null, 'currency_getter' => 'getCurrency',
            'description_getter' => null, 'client_table' => 'gc_client', 'client_entity' => 'GcClient',
            'client_id_getter' => 'getIdGcClient', 'modes' => ['payment'],
        ]]]);
        $client = new GcClient();
        $client->save();
        $cust = new StripeCustomer();
        $cust->setIdGcClient($client->getPrimaryKey());
        $cust->setStripeCustomerId('cus_1');
        $cust->setDefaultPaymentMethod('pm_1');
        $cust->save();
        $this->payable = new GcPayable();
        $this->payable->d = ['IsPaid' => 0, 'Amount' => 50.0, 'Currency' => 'USD', 'IdGcClient' => $client->getPrimaryKey()];
        $this->payable->save();
    }

    protected function tearDown(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        \Stripe\ApiRequestor::setHttpClient(null);
        StripeManifest::reset();
        FakeStore::reset();
        MicroCache::flushLocal();
    }

    private function http(array $responses): RecordingStripeHttp
    {
        $fake = new RecordingStripeHttp($responses);
        \Stripe\ApiRequestor::setHttpClient($fake);
        return $fake;
    }

    private static function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status, 'amount' => 5000, 'currency' => 'usd'];
    }

    public function testDoubleClickChargesOnce(): void
    {
        $http = $this->http([self::intent('pi_1', 'processing')]);
        $first = PaymentService::chargeSaved($this->payable, 'gc_payable');
        $this->assertSame('processing', $first['status']);

        try {
            PaymentService::chargeSaved($this->payable, 'gc_payable');
            $this->fail('second charge must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('A payment for this record is already in progress or completed', $e->getMessage());
        }
        $this->assertCount(1, $http->calls, 'exactly one PaymentIntent create reached Stripe');
        $this->assertSame('gc-ch-gc_payable-' . $this->payable->getPrimaryKey() . '-5000usd-a1', $http->idempotencyKey(0));
        $this->assertCount(1, FakeStore::all(StripePayment::class));
    }

    public function testConcurrentRequestHoldingTheLockIsRefused(): void
    {
        $http = $this->http([]);
        $this->assertTrue(MicroCache::add(PaymentService::chargeLockKey('gc_payable', (int) $this->payable->getPrimaryKey()), 60, 1));
        try {
            PaymentService::chargeSaved($this->payable, 'gc_payable');
            $this->fail('locked payable must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('A payment for this record is already in progress', $e->getMessage());
        }
        $this->assertCount(0, $http->calls);
        $this->assertCount(0, FakeStore::all(StripePayment::class));
    }

    public function testLockIsReleasedAndRetryAfterFailureUsesANewAttempt(): void
    {
        $http = $this->http([self::intent('pi_1', 'requires_payment_method'), self::intent('pi_2', 'processing')]);
        $first = PaymentService::chargeSaved($this->payable, 'gc_payable');
        $this->assertSame('requires_payment_method', $first['status']);
        $rows = FakeStore::all(StripePayment::class);
        $this->assertSame('failed', \reset($rows)->getStatus(), 'a synchronous decline is final');

        PaymentService::chargeSaved($this->payable, 'gc_payable');   // lock released, failed row does not block
        $pk = $this->payable->getPrimaryKey();
        $this->assertSame("gc-ch-gc_payable-{$pk}-5000usd-a1", $http->idempotencyKey(0));
        $this->assertSame("gc-ch-gc_payable-{$pk}-5000usd-a2", $http->idempotencyKey(1));
    }

    public function testAlreadyPaidPayableIsRefused(): void
    {
        $this->http([]);
        $this->payable->setIsPaid(1);
        $this->expectExceptionMessage('This record is already paid');
        PaymentService::chargeSaved($this->payable, 'gc_payable');
    }

    public function testPendingThreeDSecureChargeBlocksANewCharge(): void
    {
        $this->http([]);
        $row = new StripePayment();
        $row->d = ['PayableTable' => 'gc_payable', 'PayableId' => $this->payable->getPrimaryKey(), 'Status' => 'pending', 'StripePaymentIntentId' => 'pi_3ds'];
        $row->save();
        $this->expectExceptionMessage('already in progress or completed');
        PaymentService::chargeSaved($this->payable, 'gc_payable');
    }

    public function testIdempotencyKeyIsDeterministic(): void
    {
        $a = PaymentService::chargeIdempotencyKey('Invoice', 7, 5000, 'USD', 1);
        $this->assertSame($a, PaymentService::chargeIdempotencyKey('invoice', 7, 5000, 'usd', 1));
        $this->assertNotSame($a, PaymentService::chargeIdempotencyKey('invoice', 7, 5000, 'usd', 2));
        $this->assertNotSame($a, PaymentService::chargeIdempotencyKey('invoice', 7, 5001, 'usd', 1));
        $this->assertNotSame($a, PaymentService::chargeIdempotencyKey('invoice', 8, 5000, 'usd', 1));
    }

    /** @dataProvider statuses */
    public function testIntentStatusMapping(string $intent, ?string $ledger, ?string $event): void
    {
        $this->assertSame($ledger, PaymentService::intentStatusToLedger($intent));
        $this->assertSame($event, PaymentService::intentStatusEvent($intent));
    }

    public static function statuses(): array
    {
        return [
            'succeeded'               => ['succeeded', 'succeeded', 'payment_intent.succeeded'],
            'canceled'                => ['canceled', 'canceled', 'payment_intent.canceled'],
            'declined'                => ['requires_payment_method', 'failed', 'payment_intent.payment_failed'],
            'processing'              => ['processing', 'processing', null],
            '3-D Secure'              => ['requires_action', 'pending', null],
            'requires_confirmation'   => ['requires_confirmation', 'pending', null],
            'requires_capture'        => ['requires_capture', 'pending', null],
            'unknown'                 => ['something_new', null, null],
        ];
    }

    public function testPolledRequiresActionIsPendingNotFailed(): void
    {
        $this->http([self::intent('pi_3ds', 'requires_action')]);
        $row = new StripePayment();
        $row->d = ['PayableTable' => 'gc_payable', 'PayableId' => $this->payable->getPrimaryKey(), 'Status' => 'processing',
            'StripePaymentIntentId' => 'pi_3ds', 'Amount' => 5000, 'Currency' => 'usd'];
        $row->save();
        PaymentService::refreshStatus($row);
        $this->assertSame('pending', $row->getStatus());
        $this->assertNull($row->getErrorMessage());
        $this->assertSame(0, $this->payable->getIsPaid());
    }

    public function testPolledSuccessGoesThroughTheAmountCheckedFlip(): void
    {
        $this->http([self::intent('pi_ok', 'succeeded') + ['amount_received' => 5000]]);
        $row = new StripePayment();
        $row->d = ['PayableTable' => 'gc_payable', 'PayableId' => $this->payable->getPrimaryKey(), 'Status' => 'processing',
            'StripePaymentIntentId' => 'pi_ok', 'Amount' => 5000, 'Currency' => 'usd'];
        $row->save();
        PaymentService::refreshStatus($row);
        $this->assertSame('succeeded', $row->getStatus());
        $this->assertSame(1, $this->payable->getIsPaid());
    }
}
