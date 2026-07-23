<?php
// Run: php tests/StripeDbTest.php
require __DIR__ . '/../src/Stripe/StripeDb.php';

use ApiGoat\Stripe\StripeDb;

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "PASS" : "FAIL") . " $name\n"; if (!$ok) $fail++; }

try { StripeDb::query('StripeCustomer'); check('missing class throws', false); }
catch (\RuntimeException $e) { check('missing class throws', str_contains($e->getMessage(), 'StripeCustomerQuery')); }

class_alias(\stdClass::class, 'App\\FakeThingQuery');
check('existing class resolves', StripeDb::query('FakeThing') === '\\App\\FakeThingQuery');

try { StripeDb::query('Bad Name!'); check('invalid entity name throws', false); }
catch (\InvalidArgumentException $e) { check('invalid entity name throws', true); }

exit($fail ? 1 : 0);
