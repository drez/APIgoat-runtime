<?php
// Run: php tests/StripeWebhookTest.php
require __DIR__ . '/../src/Stripe/WebhookEndpoint.php';

use ApiGoat\Stripe\WebhookEndpoint;

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? "PASS" : "FAIL") . " $name\n"; if (!$ok) $fail++; }

$secret  = 'whsec_testsecret';
$payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded',
    'data' => ['object' => ['id' => 'pi_1', 'status' => 'succeeded']]]);
$ts  = 1750000000;
$sig = hash_hmac('sha256', $ts . '.' . $payload, $secret);
$header = "t={$ts},v1={$sig}";

// valid signature → decoded event
$evt = WebhookEndpoint::verifySignature($payload, $header, $secret, $ts + 60);
check('valid sig decodes', $evt['id'] === 'evt_1' && $evt['type'] === 'payment_intent.succeeded');

// tampered payload → throws
try { WebhookEndpoint::verifySignature($payload . 'x', $header, $secret, $ts + 60); check('tampered payload rejected', false); }
catch (\RuntimeException $e) { check('tampered payload rejected', true); }

// wrong secret → throws
try { WebhookEndpoint::verifySignature($payload, $header, 'whsec_other', $ts + 60); check('wrong secret rejected', false); }
catch (\RuntimeException $e) { check('wrong secret rejected', true); }

// stale timestamp (> 5 min) → throws
try { WebhookEndpoint::verifySignature($payload, $header, $secret, $ts + 600); check('stale timestamp rejected', false); }
catch (\RuntimeException $e) { check('stale timestamp rejected', true); }

// garbage header → throws
try { WebhookEndpoint::verifySignature($payload, 'nonsense', $secret, $ts); check('garbage header rejected', false); }
catch (\RuntimeException $e) { check('garbage header rejected', true); }

exit($fail ? 1 : 0);
