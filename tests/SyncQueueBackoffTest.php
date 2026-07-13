<?php
// tests/SyncQueueBackoffTest.php — Run: php tests/SyncQueueBackoffTest.php
require __DIR__ . '/../src/Sync/SyncMap.php';
require __DIR__ . '/../src/Sync/SyncQueue.php';
require __DIR__ . '/../src/Sync/Hooks.php';

use ApiGoat\Sync\Hooks;
use ApiGoat\Sync\SyncQueue;

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
    else { echo "ok   $label\n"; }
}

// Backoff: 30s / 2m / 4m30 / 8m ... capped at 2h (same curve as App JobQueue)
check('backoff 1', SyncQueue::backoffSeconds(1), 30);
check('backoff 2', SyncQueue::backoffSeconds(2), 120);
check('backoff 3', SyncQueue::backoffSeconds(3), 270);
check('backoff cap', SyncQueue::backoffSeconds(50), 7200);
check('queue unavailable without models', SyncQueue::available(), false);

// Push policy: primary roles always enqueue; party roles only when linked
$map = ['tables' => [
    'invoice' => ['role' => 'invoice'],
    'company' => ['roles' => ['customer' => [], 'vendor' => []]],
]];
check('primary enqueues', Hooks::shouldEnqueue($map, 'invoice', 5, fn () => false), true);
check('party unlinked skips', Hooks::shouldEnqueue($map, 'company', 5, fn () => false), false);
check('party linked enqueues', Hooks::shouldEnqueue($map, 'company', 5, fn () => true), true);
check('unmapped table skips', Hooks::shouldEnqueue($map, 'contact', 5, fn () => true), false);

exit($fails ? 1 : 0);
