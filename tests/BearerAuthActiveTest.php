<?php
// Run: php vendor/apigoat/runtime/tests/BearerAuthActiveTest.php
// Unit-tests the pure account-active predicate used by BearerSessionAuthenticator.
require __DIR__ . '/../src/OAuth/BearerSessionAuthenticator.php';

use ApiGoat\OAuth\BearerSessionAuthenticator;

function assertTrue($c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }

// A fake Authy exposing getDeactivate(); active means deactivate is null/empty/0/'No'.
$mk = fn($d) => new class($d) { public function __construct(public $d){} public function getDeactivate(){ return $this->d; } };

assertTrue(BearerSessionAuthenticator::isAccountActive($mk(null)) === true,  'null deactivate => active');
assertTrue(BearerSessionAuthenticator::isAccountActive($mk('')) === true,    'empty deactivate => active');
assertTrue(BearerSessionAuthenticator::isAccountActive($mk('0')) === true,   "'0' deactivate => active");
assertTrue(BearerSessionAuthenticator::isAccountActive($mk('No')) === true,  "'No' deactivate => active");
assertTrue(BearerSessionAuthenticator::isAccountActive($mk('Yes')) === false,"'Yes' deactivate => inactive");
assertTrue(BearerSessionAuthenticator::isAccountActive($mk('1')) === false,  "'1' deactivate => inactive");

// An object WITHOUT getDeactivate() defaults to active (matches McpEndpoint's method_exists guard).
$noMethod = new class {};
assertTrue(BearerSessionAuthenticator::isAccountActive($noMethod) === true, 'no getDeactivate => active default');

echo "PASS: BearerSessionAuthenticator::isAccountActive OK\n"; exit(0);
