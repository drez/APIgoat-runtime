<?php
// Run: php tests/StripeManifestTest.php
require __DIR__ . '/../src/Stripe/StripeManifest.php';

use ApiGoat\Stripe\StripeManifest;

$tmp = sys_get_temp_dir() . '/stripe-manifest-test-' . getmypid() . '/';
@mkdir($tmp . 'config/Built', 0777, true);
define('_BASE_DIR', $tmp);

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "PASS" : "FAIL") . " $name\n"; if (!$ok) $fail++; }

// 1. No manifest file → unavailable, empty
StripeManifest::reset();
check('unavailable without manifest', StripeManifest::available() === false);
check('all() empty without manifest', StripeManifest::all() === []);
check('payable() null without manifest', StripeManifest::payable('billing') === null);

// 2. With manifest → available, lookups work (case-insensitive table key)
file_put_contents($tmp . 'config/Built/stripe.php', "<?php\nreturn " . var_export([
    'payables' => ['billing' => ['entity' => 'Billing', 'amount_getter' => 'getTotal',
        'currency' => 'cad', 'currency_getter' => null, 'description_getter' => null,
        'paid_flag_setter' => null, 'client_table' => 'client', 'client_entity' => 'Client',
        'client_id_getter' => 'getIdClient', 'modes' => ['payment']]],
    'client_tables' => ['client' => 'Client'],
], true) . ";\n");
StripeManifest::reset();
check('available with manifest', StripeManifest::available() === true);
check('payable lookup', StripeManifest::payable('Billing')['entity'] === 'Billing');
check('unknown payable null', StripeManifest::payable('nope') === null);

// 3. livemode() derives from key prefix (env unset → false)
putenv('STRIPE_SECRET_KEY=sk_test_123'); check('test key → livemode false', StripeManifest::livemode() === false);
putenv('STRIPE_SECRET_KEY=sk_live_123'); check('live key → livemode true',  StripeManifest::livemode() === true);
putenv('STRIPE_SECRET_KEY');

exit($fail ? 1 : 0);
