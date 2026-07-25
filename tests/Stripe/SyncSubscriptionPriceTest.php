<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe;

// This repo carries no local vendor/phpunit install (it is a library
// consumed via a project's composer, e.g. vidifye's .admin/vendor/bin/phpunit).
// Explicitly require the source file under test — mirrors the convention
// used by the sibling PayPageReturnTest.php in this directory — so this
// test exercises THIS repo's copy regardless of what any consuming
// project has installed.
require_once __DIR__ . '/../../src/Stripe/WebhookHandler.php';

use ApiGoat\Stripe\WebhookHandler;
use PHPUnit\Framework\TestCase;

class SyncSubscriptionPriceTest extends TestCase
{
    public function testPriceIdFromSub(): void
    {
        $sub = ['items' => ['data' => [['price' => ['id' => 'price_ABC']]]]];
        $this->assertSame('price_ABC', WebhookHandler::priceIdFromSub($sub));
    }
    public function testPriceIdFromSubEmptyWhenMissing(): void
    {
        $this->assertSame('', WebhookHandler::priceIdFromSub([]));
        $this->assertSame('', WebhookHandler::priceIdFromSub(['items' => ['data' => []]]));
    }
}
