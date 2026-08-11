<?php

declare(strict_types=1);

// Deliberately namespace App: ApiGoat\Stripe\StripeDb::resolve() hard-codes
// "\App\{$entity}{$suffix}" (the real Propel-generated classes only exist in
// a consuming project, e.g. vidifye's src/App/Models/Built). These fakes let
// CheckoutServiceModeTest exercise buildSessionParams' StripeDb::query(
// 'StripePrice') lookups without a database or a project checkout — same
// trick as the FakeSubRow/FakeCustomerRow stand-ins in Fixtures.php, just
// under the namespace CheckoutService actually resolves.
namespace App;

/** Minimal stand-in for the Propel `StripePrice` row. */
final class StripePrice
{
    public function __construct(
        private readonly int $id,
        private readonly string $type,
        private readonly string $stripePriceId,
    ) {
    }

    public function getPrimaryKey(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStripePriceId(): string
    {
        return $this->stripePriceId;
    }
}

/** Minimal stand-in for `StripePriceQuery` — findPk() / filterByStripePriceId()->findOne(). */
final class StripePriceQuery
{
    /** @var array<int, StripePrice> keyed by primary key */
    public static array $byId = [];

    /** @var array<string, StripePrice> keyed by stripe_price_id */
    public static array $byStripeId = [];

    private ?string $stripeIdFilter = null;

    public static function create(): self
    {
        return new self();
    }

    /** Test seam: clear both registries between tests. */
    public static function reset(): void
    {
        self::$byId = [];
        self::$byStripeId = [];
    }

    public static function register(StripePrice $row): void
    {
        self::$byId[$row->getPrimaryKey()] = $row;
        if ($row->getStripePriceId() !== '') {
            self::$byStripeId[$row->getStripePriceId()] = $row;
        }
    }

    public function findPk($id): ?StripePrice
    {
        return self::$byId[(int) $id] ?? null;
    }

    public function filterByStripePriceId($stripePriceId): self
    {
        $this->stripeIdFilter = (string) $stripePriceId;
        return $this;
    }

    public function findOne(): ?StripePrice
    {
        if ($this->stripeIdFilter === null) {
            return null;
        }
        return self::$byStripeId[$this->stripeIdFilter] ?? null;
    }
}

/** Minimal stand-in for the payable record (ProductBoost/ProductVideoExtend shape). */
final class FakePayableRecord
{
    public function __construct(
        private readonly float $amount,
        private readonly string $currency,
        private readonly int $pk,
    ) {
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPrimaryKey(): int
    {
        return $this->pk;
    }
}

/** Minimal stand-in for the resolved StripeCustomer row buildSessionParams reads. */
final class FakeCheckoutCustomer
{
    public function __construct(private readonly string $stripeCustomerId)
    {
    }

    public function getStripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }
}
