<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Stripe\Support;

/** Minimal stand-in for the Propel `StripeSubscription` row. */
final class FakeSubRow
{
    public function __construct(private readonly string $stripeSubscriptionId)
    {
    }

    public function getStripeSubscriptionId(): string
    {
        return $this->stripeSubscriptionId;
    }
}

/** Minimal stand-in for the Propel `StripeCustomer` row. */
final class FakeCustomerRow
{
    public function __construct(private readonly string $stripeCustomerId)
    {
    }

    public function getStripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }
}
