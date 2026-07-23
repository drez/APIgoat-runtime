<?php
// Run: php tests/StripeGatewayTest.php
require __DIR__ . '/../src/Stripe/StripeGateway.php';
require __DIR__ . '/../src/Stripe/PayTokens.php';

use ApiGoat\Stripe\StripeGateway;
use ApiGoat\Stripe\PayTokens;

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "PASS" : "FAIL") . " $name\n"; if (!$ok) $fail++; }

putenv('STRIPE_SECRET_KEY');
check('fromEnv null without key', StripeGateway::fromEnv() === null);
putenv('STRIPE_SECRET_KEY=sk_test_abc');
check('fromEnv instance with key', StripeGateway::fromEnv() instanceof StripeGateway);
putenv('STRIPE_WEBHOOK_SECRET=whsec_x');
check('webhookSecret read', StripeGateway::webhookSecret() === 'whsec_x');
putenv('STRIPE_SECRET_KEY'); putenv('STRIPE_WEBHOOK_SECRET');

check('minorUnits 123.45 → 12345', StripeGateway::minorUnits(123.45) === 12345);
check('minorUnits 0.1+0.2 float-safe', StripeGateway::minorUnits(0.30000000000000004) === 30);
check('minorUnits 19.999 rounds', StripeGateway::minorUnits(19.999) === 2000);

$t = PayTokens::mint();
check('token 64 hex chars', (bool) preg_match('/^[0-9a-f]{64}$/', $t['token']));
check('hash matches', PayTokens::hash($t['token']) === $t['hash']);
check('hash is sha256 hex', (bool) preg_match('/^[0-9a-f]{64}$/', $t['hash']));
check('two mints differ', PayTokens::mint()['token'] !== $t['token']);

exit($fail ? 1 : 0);
