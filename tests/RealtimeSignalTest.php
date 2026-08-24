<?php
// Run: php tests/RealtimeSignalTest.php   (from the runtime repo root)
//
// Realtime\Signal is called from TableVersion::bump(), i.e. on EVERY ORM write
// in EVERY project. What is tested here is therefore mostly what it must NOT
// do: throw, block, or cost anything when the project has not opted in.

require __DIR__ . '/../src/Realtime/Signal.php';

use ApiGoat\Realtime\Signal;

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

$tmp = sys_get_temp_dir() . '/gc-rt-signal-test-' . getmypid();
@mkdir($tmp . '/tmp', 0775, true);
define('_BASE_DIR', $tmp . '/');

// --- the knob ---------------------------------------------------------------
foreach (['0' => false, '' => false, 'off' => false, 'no' => false,
          '1' => true, 'true' => true, 'on' => true, 'yes' => true, 'YES' => true] as $v => $want) {
    putenv('GC_RT_ENABLED=' . $v);
    Signal::reset();
    check("GC_RT_ENABLED='{$v}' -> " . var_export($want, true), Signal::enabled(), $want);
}

// --- path resolution --------------------------------------------------------
putenv('GC_RT_SOCK=');
Signal::reset();
check('default socket path is _BASE_DIR/tmp/rt.sock', Signal::socketPath(), $tmp . '/tmp/rt.sock');
putenv('GC_RT_SOCK=tmp/other.sock');
check('relative GC_RT_SOCK resolves against _BASE_DIR', Signal::socketPath(), $tmp . '/tmp/other.sock');
putenv('GC_RT_SOCK=/var/run/gc.sock');
check('absolute GC_RT_SOCK is used as-is', Signal::socketPath(), '/var/run/gc.sock');
putenv('GC_RT_SOCK=');

// --- it must never throw ----------------------------------------------------
// Disabled: the common case for the whole fleet.
putenv('GC_RT_ENABLED=0');
Signal::reset();
$threw = false;
try { Signal::emit('client', '1', 'all'); } catch (\Throwable $e) { $threw = true; }
check('emit() while disabled does not throw', $threw, false);

// Enabled but no sidecar listening — the socket file does not exist.
putenv('GC_RT_ENABLED=1');
Signal::reset();
$threw = false;
try { Signal::emit('client', '1', 'all'); } catch (\Throwable $e) { $threw = true; }
check('emit() with no sidecar does not throw', $threw, false);

// Enabled, and the path exists but is a PLAIN FILE, not a socket: a stale
// leftover must not turn a write into a fatal.
$stale = $tmp . '/tmp/rt.sock';
file_put_contents($stale, 'not a socket');
Signal::reset();
$threw = false;
try { Signal::emit('client', '1', 'all'); } catch (\Throwable $e) { $threw = true; }
check('emit() against a non-socket file does not throw', $threw, false);
@unlink($stale);

// A burst must stay silent too — the memoised failure path is what keeps a
// 500-row import from retrying the connect 500 times.
Signal::reset();
$threw = false;
try { for ($i = 0; $i < 500; $i++) { Signal::emit('client', (string) $i, 'all'); } }
catch (\Throwable $e) { $threw = true; }
check('a 500-write burst with no sidecar does not throw', $threw, false);

// --- the disabled path must be cheap ---------------------------------------
putenv('GC_RT_ENABLED=0');
Signal::reset();
$t0 = microtime(true);
for ($i = 0; $i < 100000; $i++) { Signal::emit('client', '1', 'all'); }
$usEach = (microtime(true) - $t0) * 1e6 / 100000;
// Generous ceiling: this runs on CI boxes and under Xdebug. The point is to
// catch a regression that puts real work (a stat, a connect) back on the
// disabled path, not to benchmark.
check('disabled emit() stays under 20us each (' . round($usEach, 2) . 'us)', $usEach < 20, true);

@unlink($tmp . '/tmp/rt.sock');
@rmdir($tmp . '/tmp');
@rmdir($tmp);

echo $fail === 0 ? "\nAll good.\n" : "\n{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
