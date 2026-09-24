<?php
// Run: php tests/RealtimeTicketTest.php   (from the runtime repo root)
//
// Realtime handshake ticket: mint/verify round-trip plus every way a ticket
// must be refused. This is the ONLY thing standing between an anonymous
// WebSocket connect and a subscription, so the negative cases matter more
// than the happy path.

require __DIR__ . '/../src/Realtime/Ticket.php';
require __DIR__ . '/../src/Realtime/Hooks.php';
require __DIR__ . '/../src/Realtime/Server.php';

use ApiGoat\Realtime\Server;
use ApiGoat\Realtime\Ticket;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    $ok = $got === $want;
    echo ($ok ? '  ok  ' : '  FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $fail++;
        echo '        got:  ' . var_export($got, true) . "\n";
        echo '        want: ' . var_export($want, true) . "\n";
    }
}

putenv('JWT_SECRET=unit-test-secret');

// --- round trip -------------------------------------------------------------
$t = Ticket::mint(42, 't7', ['product', 'invoice']);
check('mint returns a ticket', $t !== '', true);
$claims = Ticket::verify($t);
check('verify returns the authy id', $claims['u'] ?? null, 42);
check('verify returns the tenant token', $claims['tn'] ?? null, 't7');
check('verify returns the readable tables', $claims['tb'] ?? null, ['product', 'invoice']);
check('allowsTable: listed table', Ticket::allowsTable($claims, 'product'), true);
check('allowsTable: unlisted table', Ticket::allowsTable($claims, 'authy'), false);
check('wildcard ticket allows any table', Ticket::allowsTable(Ticket::verify(Ticket::mint(1, 'all', ['*'])), 'authy'), true);
check('no session => no tables', Ticket::verify(Ticket::mint(5, 'all'))['tb'] ?? null, []);
check('empty tenant becomes tnone, never all', Ticket::verify(Ticket::mint(5, ''))['tn'] ?? null, 'tnone');
$many = [];
for ($i = 0; $i < Ticket::MAX_TABLES + 50; $i++) { $many[] = 'tbl' . $i; }
check('table list is capped', count(Ticket::verify(Ticket::mint(5, 'all', $many))['tb']), Ticket::MAX_TABLES);
check('ticket has exactly two dot-separated parts', substr_count($t, '.'), 1);
check('payload is not readable as plain json', str_contains($t, '"u"'), false);

// --- refusals ---------------------------------------------------------------
check('empty string is refused', Ticket::verify(''), null);
check('garbage is refused', Ticket::verify('not-a-ticket'), null);
check('body without signature is refused', Ticket::verify(explode('.', $t)[0]), null);
check('extra segments are refused', Ticket::verify($t . '.extra'), null);

// A tampered signature must fail even though the body is untouched.
[$body, $sig] = explode('.', $t);
check('tampered signature is refused', Ticket::verify($body . '.' . strrev($sig)), null);

// A tampered BODY must fail even though it is well-formed base64url json —
// otherwise anyone could mint themselves another user's identity.
$forged = rtrim(strtr(base64_encode((string) json_encode(['u' => 999, 'tn' => 'all', 'e' => time() + 60])), '+/', '-_'), '=');
check('re-signed-by-nobody body is refused', Ticket::verify($forged . '.' . $sig), null);

// --- expiry -----------------------------------------------------------------
$sign = new ReflectionMethod(Ticket::class, 'sign');
$sign->setAccessible(true);
$expired = $sign->invoke(null, ['u' => 1, 'tn' => 'all', 'tb' => [], 'e' => time() - 1], Ticket::key());
check('expired ticket is refused', Ticket::verify($expired), null);
$fresh = $sign->invoke(null, ['u' => 1, 'tn' => 'all', 'tb' => [], 'e' => time() + 5], Ticket::key());
check('unexpired ticket is accepted', (Ticket::verify($fresh)['u'] ?? null), 1);
$noTn = $sign->invoke(null, ['u' => 1, 'e' => time() + 5], Ticket::key());
check('ticket without tn is tnone (not all)', Ticket::verify($noTn)['tn'] ?? null, 'tnone');
check('ticket without tb subscribes nothing', Ticket::verify($noTn)['tb'] ?? null, []);

// --- key separation (HKDF) + legacy transition ------------------------------
check('ticket key is not the raw JWT_SECRET', Ticket::key() !== 'unit-test-secret', true);
check('ticket key is 32 bytes', strlen(Ticket::key()), 32);
$legacyTicket = $sign->invoke(null, ['u' => 3, 'tn' => 't1', 'e' => time() + 20], 'unit-test-secret');
check('raw-JWT_SECRET ticket refused outside the legacy window', Ticket::verify($legacyTicket), null);
Ticket::openLegacyWindow(60);
$lc = Ticket::verify($legacyTicket);
check('raw-JWT_SECRET ticket accepted inside the legacy window', $lc['u'] ?? null, 3);
check('legacy ticket keeps its old all-tables grant', $lc['tb'] ?? null, ['*']);
$legacyExpired = $sign->invoke(null, ['u' => 3, 'tn' => 't1', 'e' => time() - 1], 'unit-test-secret');
check('expired legacy ticket refused even inside the window', Ticket::verify($legacyExpired), null);
Ticket::openLegacyWindow(0);
check('closing the window refuses legacy again', Ticket::verify($legacyTicket), null);

// --- sidecar caps (pure helpers) --------------------------------------------
$clients = [1 => ['u' => 7], 2 => ['u' => 7], 3 => ['u' => 8]];
check('under caps accepts', Server::connectionRefusal($clients, 7, 10, 3), null);
check('per-user cap refuses', Server::connectionRefusal($clients, 7, 10, 2), 'per-user connection cap');
check('other user unaffected by per-user cap', Server::connectionRefusal($clients, 9, 10, 2), null);
check('global cap refuses', Server::connectionRefusal($clients, 9, 3, 2), 'global connection cap');
$cl = ['tb' => ['a', 'b', 'c'], 'tables' => ['a' => true]];
check('maySubscribe: listed table', Server::maySubscribe($cl, 'b', 5), true);
check('maySubscribe: unlisted table refused', Server::maySubscribe($cl, 'authy', 5), false);
check('maySubscribe: cap reached refuses a new table', Server::maySubscribe($cl, 'b', 1), false);
check('maySubscribe: already-subscribed table fine at cap', Server::maySubscribe($cl, 'a', 1), true);

// --- key isolation ----------------------------------------------------------
// A ticket from another project's secret must never validate here.
putenv('JWT_SECRET=a-different-project-secret');
check('ticket signed with another secret is refused', Ticket::verify($t), null);
putenv('JWT_SECRET=');
check('no secret configured refuses everything', Ticket::verify($t), null);
check('no secret configured mints nothing', Ticket::mint(42, 'all', ['*']), '');

// --- no session, no ticket --------------------------------------------------
putenv('JWT_SECRET=unit-test-secret');
check('mint without a connected user returns empty', Ticket::mint(), '');
check('mint with a bogus id returns empty', Ticket::mint(0, 'all'), '');

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
