<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

require_once __DIR__ . '/../../src/Stripe/StripeDb.php';
require_once __DIR__ . '/../../src/Stripe/StripeGateway.php';
require_once __DIR__ . '/../../src/Stripe/StripeManifest.php';
require_once __DIR__ . '/../../src/Stripe/PayTokens.php';
require_once __DIR__ . '/../../src/Stripe/CheckoutService.php';
require_once __DIR__ . '/../../src/Stripe/PayPage.php';
require_once __DIR__ . '/support/FakeLedgerDb.php';

// Propel's Criteria is not installed in this library repo; FakeQuery only
// needs its comparison constants.
if (!\class_exists('Criteria')) {
    eval('class Criteria { const ISNULL = " IS NULL "; const NOT_EQUAL = "<>"; const GREATER_THAN = ">"; }');
}

use ApiGoat\Stripe\PayPage;
use ApiGoat\Stripe\PayTokens;
use ApiGoat\Stripe\StripeManifest;
use ApiGoat\Tests\Stripe\Support\FakeStore;
use App\StripePayment;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * SECURITY: the pay page must never regenerate a Checkout session whose
 * stored one is 'complete' (paid, or an async debit still processing) —
 * doing so overwrote the session id the late checkout.session.completed
 * webhook matches on, and handed the payer a second payable link.
 */
final class PayPageCompletedSessionTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        FakeStore::reset();
        StripeManifest::reset(['payables' => ['invoice' => ['entity' => 'GcPayable']]]);
        \putenv('STRIPE_SECRET_KEY=sk_test_fake');
        $this->token = \str_repeat('ab', 32);
        $r = new StripePayment();
        $r->d = [
            'PayableTable' => 'invoice', 'PayableId' => 7, 'Status' => 'pending',
            'StripeCheckoutSessionId' => 'cs_old', 'Amount' => 1000, 'Currency' => 'cad',
            'PayTokenHash' => PayTokens::hash($this->token), 'PayTokenExpires' => \time() + 3600,
        ];
        $r->save();
    }

    protected function tearDown(): void
    {
        \putenv('STRIPE_SECRET_KEY');
        \Stripe\ApiRequestor::setHttpClient(null);
        StripeManifest::reset();
        FakeStore::reset();
    }

    /** @param array<int, array{0:int,1:array}> $responses [httpStatus, body] per request */
    private function fake(array $responses): object
    {
        $fake = new class ($responses) implements \Stripe\HttpClient\ClientInterface {
            public array $calls = [];
            public function __construct(private array $responses)
            {
            }
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->calls[] = $method . ' ' . $absUrl;
                [$code, $body] = \array_shift($this->responses) ?? [500, ['error' => ['message' => 'unexpected request']]];
                return [(string) \json_encode($body), $code, []];
            }
        };
        \Stripe\ApiRequestor::setHttpClient($fake);
        return $fake;
    }

    private function render(): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/stripe/pay/' . $this->token);
        $res = PayPage::render($req, (new ResponseFactory())->createResponse(), $this->token);
        return [$res->getStatusCode(), (string) $res->getBody()];
    }

    private function row(): StripePayment
    {
        return FakeStore::all(StripePayment::class)[1];
    }

    public function testCompletedPaidSessionIsNotRegenerated(): void
    {
        $fake = $this->fake([[200, ['id' => 'cs_old', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid']]]);
        [$code, $body] = $this->render();
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Payment received', $body);
        $this->assertStringNotContainsString('Pay now', $body);
        $this->assertSame('cs_old', $this->row()->getStripeCheckoutSessionId());
        $this->assertCount(1, $fake->calls, 'no session creation after a complete one');
    }

    public function testCompletedProcessingSessionIsNotRegenerated(): void
    {
        $fake = $this->fake([[200, ['id' => 'cs_old', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'unpaid']]]);
        [$code, $body] = $this->render();
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Payment processing', $body);
        $this->assertStringNotContainsString('Pay now', $body);
        $this->assertSame('cs_old', $this->row()->getStripeCheckoutSessionId());
        $this->assertCount(1, $fake->calls);
    }

    public function testTransientRetrieveFailureDoesNotRegenerate(): void
    {
        $fake = $this->fake([[500, ['error' => ['message' => 'boom', 'type' => 'api_error']]]]);
        \Stripe\Stripe::setMaxNetworkRetries(0);
        [$code] = $this->render();
        $this->assertSame(503, $code);
        $this->assertSame('cs_old', $this->row()->getStripeCheckoutSessionId());
        $this->assertCount(1, $fake->calls);
    }

    public function testOpenSessionStillRendersPayLink(): void
    {
        $this->fake([[200, ['id' => 'cs_old', 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/cs_old']]]);
        [$code, $body] = $this->render();
        $this->assertSame(200, $code);
        $this->assertStringContainsString('https://checkout.stripe.com/c/pay/cs_old', $body);
        $this->assertSame('cs_old', $this->row()->getStripeCheckoutSessionId());
    }
}
