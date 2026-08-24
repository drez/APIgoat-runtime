<?php
// Run: php tests/RealtimeTicketTest.php   (from the runtime repo root)
//
// Realtime handshake ticket: mint/verify round-trip plus every way a ticket
// must be refused. This is the ONLY thing standing between an anonymous
// WebSocket connect and a subscription, so the negative cases matter more
// than the happy path.

require __DIR__ . '/../src/Realtime/Ticket.php';

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
$t = Ticket::mint(42, 't7');
check('mint returns a ticket', $t !== '', true);
$claims = Ticket::verify($t);
check('verify returns the authy id', $claims['u'] ?? null, 42);
check('verify returns the tenant token', $claims['tn'] ?? null, 't7');
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
$expired = $sign->invoke(null, ['u' => 1, 'tn' => 'all', 'e' => time() - 1], 'unit-test-secret');
check('expired ticket is refused', Ticket::verify($expired), null);
$fresh = $sign->invoke(null, ['u' => 1, 'tn' => 'all', 'e' => time() + 5], 'unit-test-secret');
check('unexpired ticket is accepted', (Ticket::verify($fresh)['u'] ?? null), 1);

// --- key isolation ----------------------------------------------------------
// A ticket from another project's secret must never validate here.
putenv('JWT_SECRET=a-different-project-secret');
check('ticket signed with another secret is refused', Ticket::verify($t), null);
putenv('JWT_SECRET=');
check('no secret configured refuses everything', Ticket::verify($t), null);
check('no secret configured mints nothing', Ticket::mint(42, 'all'), '');

// --- no session, no ticket --------------------------------------------------
putenv('JWT_SECRET=unit-test-secret');
check('mint without a connected user returns empty', Ticket::mint(), '');
check('mint with a bogus id returns empty', Ticket::mint(0, 'all'), '');

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
