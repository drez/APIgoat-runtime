<?php

namespace ApiGoat\Stripe;

/**
 * Generic access to the with_stripe companion Propel models. Classes exist
 * only in projects that use the behavior, so they are resolved by name with
 * a hard, descriptive failure — never referenced literally from runtime code.
 */
final class StripeDb
{
    public static function query(string $entity): string
    {
        return self::resolve($entity, 'Query');
    }

    public static function model(string $entity): string
    {
        return self::resolve($entity, '');
    }

    private static function resolve(string $entity, string $suffix): string
    {
        if (!\preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $entity)) {
            throw new \InvalidArgumentException("Invalid entity name: {$entity}");
        }
        $fqcn = "\\App\\{$entity}{$suffix}";
        if (!\class_exists($fqcn)) {
            throw new \RuntimeException("{$entity}{$suffix} not found — is with_stripe built in this project? (run gc build)");
        }
        return $fqcn;
    }

    /**
     * Find-or-create the stripe_customer row (and Stripe-side Customer) for a
     * client record. $entry is the manifest payable entry (client_* keys).
     */
    public static function customerFor(object $clientRec, array $entry, StripeGateway $gw): object
    {
        $q       = self::query('StripeCustomer');
        $fkSetter = 'setId' . $entry['client_entity'];
        $fkFilter = 'filterById' . $entry['client_entity'];
        $pkGetter = 'getPrimaryKey';

        $existing = $q::create()->{$fkFilter}($clientRec->{$pkGetter}())->findOne();
        if ($existing !== null) {
            return $existing;
        }

        $email = \method_exists($clientRec, 'getEmail') ? (string) $clientRec->getEmail() : '';
        $name  = \method_exists($clientRec, 'getName') ? (string) $clientRec->getName() : ('#' . $clientRec->{$pkGetter}());

        $customer = $gw->client()->customers->create(\array_filter([
            'email'    => $email !== '' ? $email : null,
            'name'     => $name,
            'metadata' => ['gc_client_table' => $entry['client_table'], 'gc_client_id' => (string) $clientRec->{$pkGetter}()],
        ]));

        $model = self::model('StripeCustomer');
        $row = new $model();
        $row->{$fkSetter}($clientRec->{$pkGetter}());
        $row->setStripeCustomerId($customer->id);
        $row->setEmail($email);
        $row->setLivemode(StripeManifest::livemode() ? 1 : 0);
        $row->save();
        return $row;
    }
}
