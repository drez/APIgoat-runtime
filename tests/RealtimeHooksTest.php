<?php
// Run: php tests/RealtimeHooksTest.php   (from the runtime repo root)
//
// The project-extension hook layer. What matters is containment: a project
// handler runs inside a single long-lived event loop, so a broken one must
// degrade to no handler rather than take the sidecar down.

require __DIR__ . '/../src/Realtime/Hooks.php';

use ApiGoat\Realtime\Hooks;

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

$logged = [];
Hooks::setLogger(function (string $l) use (&$logged) { $logged[] = $l; });

// --- no handler: every call is a no-op returning the default ----------------
Hooks::reset();
check('present() is false when the project ships no handler', Hooks::present(), false);
check('a call returns the default', Hooks::call('onOpen', [1, []], 'dflt'), 'dflt');
check('allowSubscribe defaults to allow', Hooks::call('allowSubscribe', ['t', []], true), true);
check('allowPush defaults to null (built-in rule)', Hooks::call('allowPush', ['t', 'all', []], null), null);

// --- a handler that implements some hooks ----------------------------------
eval('namespace App\Domains; class RealtimeHandler {
    public static array $seen = [];
    public static int $throws = 0;
    public static function onOpen(int $fd, array $claims): void { self::$seen[] = "open:$fd"; }
    public static function allowSubscribe(string $t, array $c): bool { return $t !== "secret"; }
    public static function onMessage($srv, int $fd, array $msg, array $c): bool { return ($msg["op"] ?? "") === "mine"; }
    public static function boom() { self::$throws++; throw new \RuntimeException("nope"); }
}');
Hooks::reset();
check('present() is true once the class exists', Hooks::present(), true);

Hooks::call('onOpen', [7, ['u' => 1, 'tn' => 'all']]);
check('an implemented hook runs', \App\Domains\RealtimeHandler::$seen, ['open:7']);

check('allowSubscribe can refuse a table', Hooks::call('allowSubscribe', ['secret', []], true), false);
check('allowSubscribe allows the rest', Hooks::call('allowSubscribe', ['client', []], true), true);
check('onMessage can claim a frame', Hooks::call('onMessage', [null, 1, ['op' => 'mine'], []], false), true);
check('onMessage declines the rest', Hooks::call('onMessage', [null, 1, ['op' => 'sub'], []], false), false);

// A hook the handler does not implement must fall back to the default, not fatal.
check('an unimplemented hook returns the default', Hooks::call('onClose', [1], 'dflt'), 'dflt');

// --- containment ------------------------------------------------------------
$logged = [];
check('a throwing hook returns the default instead of propagating', Hooks::call('boom', [], 'safe'), 'safe');
check('the throw is logged', count($logged) > 0, true);

// Repeated throws switch the hook off, so a broken handler cannot burn the
// event loop on every single frame for days.
for ($i = 0; $i < 20; $i++) {
    Hooks::call('boom', [], 'safe');
}
$calls = \App\Domains\RealtimeHandler::$throws;
check('a persistently throwing hook is disabled (<= 10 calls, got ' . $calls . ')', $calls <= 10, true);
check('the disable is announced', (bool) preg_grep('/DISABLED/', $logged), true);
check('other hooks still work after one is disabled',
    Hooks::call('allowSubscribe', ['client', []], true), true);

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
