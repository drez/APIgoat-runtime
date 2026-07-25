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
require_once __DIR__ . '/../../src/Stripe/PortalService.php';
require_once __DIR__ . '/support/FakeStripeHttpClient.php';
require_once __DIR__ . '/support/Fixtures.php';

use ApiGoat\Stripe\PortalService;
use ApiGoat\Tests\Stripe\Support\FakeStripeHttpClient;
use ApiGoat\Tests\Stripe\Support\FakeCustomerRow;
use PHPUnit\Framework\TestCase;

final class PortalServiceCreateSessionTest extends TestCase
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
        $customer = new FakeCustomerRow('cus_123');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STRIPE_SECRET_KEY is not configured');
        PortalService::createSession($customer, 'https://app.example.com/account');
    }

    public function testReturnsPortalSessionUrl(): void
    {
        $fake = new FakeStripeHttpClient([
            [
                'id'         => 'bps_1',
                'object'     => 'billing_portal.session',
                'customer'   => 'cus_123',
                'return_url' => 'https://app.example.com/account',
                'url'        => 'https://billing.stripe.com/session/xyz',
            ],
        ]);
        \Stripe\ApiRequestor::setHttpClient($fake);

        $customer = new FakeCustomerRow('cus_123');
        $url = PortalService::createSession($customer, 'https://app.example.com/account');

        $this->assertSame('https://billing.stripe.com/session/xyz', $url);
        $this->assertIsString($url);

        $this->assertSame('post', $fake->calls[0]['method']);
        $this->assertStringContainsString('/v1/billing_portal/sessions', $fake->calls[0]['url']);
        $this->assertSame('cus_123', $fake->calls[0]['params']['customer']);
        $this->assertSame('https://app.example.com/account', $fake->calls[0]['params']['return_url']);
    }
}
