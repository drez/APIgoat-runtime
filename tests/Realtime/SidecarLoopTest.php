<?php
// Run: php tests/Realtime/SidecarLoopTest.php <admin-dir> [port]
//   e.g. php tests/Realtime/SidecarLoopTest.php /var/www/gc/p/test/.admin 9897
//
// INTEGRATION test — needs a running sidecar (`gc rt <project> start`) and the
// openswoole extension. Skips (exit 0) when either is absent, so it is safe to
// run from a plain loop over tests/.
//
// It exercises the whole path the unit tests cannot: handshake, subscription
// filtering, tenant filtering, and the FPM->sidecar datagram. Worth keeping:
// this is the test that caught stream_socket_client('udg://') unlinking the
// sidecar's own socket under OpenSwoole's coroutine hooks.

$admin = $argv[1] ?? '';
$port  = (int) ($argv[2] ?? 0);

if (!extension_loaded('openswoole')) {
    echo "SKIP: openswoole extension not loaded\n";
    exit(0);
}
if ($admin === '' || !is_dir($admin) || !is_file($admin . '/vendor/autoload.php')) {
    echo "SKIP: usage: php tests/Realtime/SidecarLoopTest.php <admin-dir> [port]\n";
    exit(0);
}

require $admin . '/vendor/autoload.php';
(new \Ahc\Env\Loader())->load(dirname($admin) . '/.env');
if (!defined('_BASE_DIR')) {
    define('_BASE_DIR', rtrim($admin, '/') . '/');
}

use ApiGoat\Realtime\Signal;
use ApiGoat\Realtime\Ticket;

if ($port <= 0) {
    $port = (int) (getenv('GC_RT_PORT') ?: 0);
}
if ($port <= 0) {
    echo "SKIP: no port given and GC_RT_PORT is unset\n";
    exit(0);
}
$probe = @fsockopen('127.0.0.1', $port, $e, $m, 1);
if (!$probe) {
    echo "SKIP: no sidecar listening on 127.0.0.1:{$port} (start it with `gc rt <project> start`)\n";
    exit(0);
}
fclose($probe);

$fail = 0;
$check = function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '  ok  ' : '  FAIL') . '  ' . $label . "\n";
    if (!$ok) { $fail++; }
};

OpenSwoole\Coroutine::run(function () use ($port, $check) {
    // --- handshake ----------------------------------------------------------
    $root = new OpenSwoole\Coroutine\Http\Client('127.0.0.1', $port);
    $root->set(['timeout' => 5]);
    $check('valid ticket completes the handshake',
        (bool) $root->upgrade('/?ticket=' . urlencode(Ticket::mint(1, 'all'))));
    $f = $root->recv(2);
    $check('server greets with {op:ready}', $f && str_contains($f->data, '"ready"'));

    // --- subscription filtering --------------------------------------------
    $root->push((string) json_encode(['op' => 'sub', 'tables' => ['client']]));
    OpenSwoole\Coroutine::sleep(0.2);

    Signal::reset();
    Signal::emit('client', '7', 'all');
    $f = $root->recv(2);
    $check('a subscribed table is pushed',
        $f && str_contains($f->data, '"change"') && str_contains($f->data, '"client"'));

    Signal::reset();
    Signal::emit('invoice', '1', 'all');
    $f = $root->recv(1);
    $check('an unsubscribed table is NOT pushed',
        !$f || !str_contains((string) ($f->data ?? ''), 'invoice'));

    // --- tenant filtering ---------------------------------------------------
    $tenant = new OpenSwoole\Coroutine\Http\Client('127.0.0.1', $port);
    $tenant->set(['timeout' => 5]);
    $tenant->upgrade('/?ticket=' . urlencode(Ticket::mint(2, 't9')));
    $tenant->recv(2);
    $tenant->push((string) json_encode(['op' => 'sub', 'tables' => ['client']]));
    OpenSwoole\Coroutine::sleep(0.2);

    Signal::reset();
    Signal::emit('client', '8', 't5');
    $check('tenant t9 does NOT see a tenant t5 write', $tenant->recv(1) === false);
    $f = $root->recv(1);
    $check("root ('all') DOES see a tenant t5 write", $f && str_contains($f->data, '"change"'));

    // --- the handshake is the authorization boundary ------------------------
    $good = Ticket::mint(1, 'all');
    $bad = ['' => 'no ticket', 'garbage' => 'garbage ticket', substr($good, 0, -3) . 'zzz' => 'tampered ticket'];
    foreach ($bad as $ticket => $label) {
        $c = new OpenSwoole\Coroutine\Http\Client('127.0.0.1', $port);
        $c->set(['timeout' => 3]);
        $up = $c->upgrade('/?ticket=' . urlencode((string) $ticket));
        $frame = $up ? $c->recv(1) : false;
        $check("{$label} is rejected",
            !$up || !$frame || $frame instanceof OpenSwoole\WebSocket\CloseFrame);
        $c->close();
    }

    $root->close();
    $tenant->close();
});

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
