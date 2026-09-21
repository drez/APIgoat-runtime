<?php
// invoice.paid renewals were silently dropped: the pinned API version (basil)
// removed invoice.payment_intent, and recordInvoicePayment() read nothing else.
namespace ApiGoat\Tests\Stripe;

use ApiGoat\Stripe\WebhookHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Stripe/WebhookHandler.php';

final class WebhookInvoiceShapeTest extends TestCase
{
    public function test_pre_basil_shape(): void
    {
        $this->assertSame('pi_old', WebhookHandler::invoicePaymentIntent(['payment_intent' => 'pi_old']));
        $this->assertSame('pi_exp', WebhookHandler::invoicePaymentIntent(['payment_intent' => ['id' => 'pi_exp', 'object' => 'payment_intent']]));
    }

    public function test_basil_shape_prefers_the_paid_payment(): void
    {
        $invoice = ['id' => 'in_1', 'payments' => ['data' => [
            ['status' => 'canceled', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_failed']],
            ['status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => ['id' => 'pi_paid']]],
        ]]];
        $this->assertSame('pi_paid', WebhookHandler::invoicePaymentIntent($invoice));
        $invoice['payments']['data'][1]['status'] = 'open';
        $this->assertSame('pi_failed', WebhookHandler::invoicePaymentIntent($invoice), 'no paid entry: first intent');
    }

    public function test_no_intent_anywhere(): void
    {
        $this->assertSame('', WebhookHandler::invoicePaymentIntent([]));
        $this->assertSame('', WebhookHandler::invoicePaymentIntent(['payment_intent' => null, 'payments' => ['data' => [['payment' => ['type' => 'charge', 'charge' => 'ch_1']]]]]));
    }

    public function test_getter_is_derived_from_the_prefix_only(): void
    {
        $this->assertSame('getIsActive', WebhookHandler::getterFor('setIsActive'));
        $this->assertSame('getAssetPaid', WebhookHandler::getterFor('setAssetPaid'));
        $this->assertSame('getResetDone', WebhookHandler::getterFor('setResetDone'));
    }
}
