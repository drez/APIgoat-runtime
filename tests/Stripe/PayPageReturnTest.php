<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

// This repo carries no local vendor/phpunit install (it is a library
// consumed via a project's composer, e.g. vidifye's .admin/vendor/bin/phpunit).
// Explicitly require the source file under test — mirrors the convention
// used by the sibling plain-script Stripe tests in ../ (StripeGatewayTest.php
// etc.) — so this test exercises THIS repo's copy regardless of what any
// consuming project has installed.
require_once __DIR__ . '/../../src/Stripe/PayPage.php';

use ApiGoat\Stripe\PayPage;
use PHPUnit\Framework\TestCase;

final class PayPageReturnTest extends TestCase
{
    public function testNullWhenNoBase(): void
    {
        $this->assertNull(PayPage::returnTarget('success', ''));
        $this->assertNull(PayPage::returnTarget('success', null));
    }

    public function testBuildsBillingUrl(): void
    {
        $this->assertSame('https://x/account?billing=success', PayPage::returnTarget('success', 'https://x/account'));
        $this->assertSame('https://x/account?billing=cancel', PayPage::returnTarget('cancel', 'https://x/account/'));
    }
}
