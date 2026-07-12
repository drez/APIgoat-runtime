<?php
// Run: php tests/BearerLegacyActionsTest.php   (from the runtime repo root)
//
// OAuthResourceMiddleware::legacyBearerActions() — which non-API (is_api=false)
// actions a bearer client may authenticate to.
//
// The built-ins (upload/open/file/mass) are framework routes. Projects add their
// OWN legacy actions (apigoatacc: Cost/scanReceipt, Client/scanAndCreateClient —
// the dashboard's receipt and business-card scans), and a mobile client could not
// reach them at all: no bearer identity was hydrated, so the request arrived
// unauthenticated. Extending a hardcoded const per project is not an option in a
// shared runtime, so the list is settings-driven.

// The class implements PSR-15 MiddlewareInterface, so the PSR contracts must be
// on the autoloader before it can be declared (the pure helpers under test need
// no container, no request — just the class).
require __DIR__ . '/../../../autoload.php';
require __DIR__ . '/../src/Middlewares/OAuthResourceMiddleware.php';

use ApiGoat\Middlewares\OAuthResourceMiddleware;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) { echo "  ok  {$label}\n"; return; }
    $fail++;
    echo "  FAIL {$label}\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
}

// No project settings -> the framework built-ins, unchanged.
check(
    'built-ins with no settings',
    OAuthResourceMiddleware::legacyBearerActions([]),
    ['upload', 'open', 'file', 'mass']
);

// A project's own legacy actions are appended (deduped, order-stable).
check(
    'project actions appended',
    OAuthResourceMiddleware::legacyBearerActions(['bearer_legacy_actions' => ['scanReceipt', 'scanAndCreateClient']]),
    ['upload', 'open', 'file', 'mass', 'scanReceipt', 'scanAndCreateClient']
);
check(
    'duplicates collapse',
    OAuthResourceMiddleware::legacyBearerActions(['bearer_legacy_actions' => ['upload', 'scanReceipt']]),
    ['upload', 'open', 'file', 'mass', 'scanReceipt']
);
// Junk in settings must never widen the surface with empty/non-string entries:
// an empty action would match a request with no action at all.
check(
    'junk ignored',
    OAuthResourceMiddleware::legacyBearerActions(['bearer_legacy_actions' => ['', null, 42, ' ', 'ok']]),
    ['upload', 'open', 'file', 'mass', 'ok']
);
check(
    'non-array setting ignored',
    OAuthResourceMiddleware::legacyBearerActions(['bearer_legacy_actions' => 'scanReceipt']),
    ['upload', 'open', 'file', 'mass']
);

// The gate itself is unchanged: bearer identity only for API routes or a listed
// legacy action, and never when a session is already connected.
check('api route + bearer', OAuthResourceMiddleware::shouldAttempt(true, false, true, false), true);
check('legacy listed + bearer', OAuthResourceMiddleware::shouldAttempt(false, false, true, true), true);
check('legacy UNlisted + bearer', OAuthResourceMiddleware::shouldAttempt(false, false, true, false), false);
check('already connected', OAuthResourceMiddleware::shouldAttempt(true, true, true, true), false);

echo $fail === 0 ? "PASS: bearer legacy actions OK\n" : "FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
