<?php
// Run: php tests/Builder/NotOkEnvelopeTest.php
//
// handleNotOkResponse() is the path that the generated Service.php FK
// delete-refusal halt()s through (bypassing renderXHR). With the S4 envelope on
// (GC_ENVELOPE=1 + X-GC-Envelope header) it must stop with the canonical
// {status:'refused', messages[]} envelope instead of the legacy <script> body;
// with it off it must keep returning the legacy onReadyJs. Since T11 it throws
// an ApiGoat\Http\HaltResponse instead of die()ing (so the route closure can
// send the payload back out through the middleware stack); the child echoes the
// halt body so the assertions below are unchanged.

if (($argv[1] ?? '') === 'child') {
    require __DIR__ . '/../../src/Http/HaltResponse.php';
    require __DIR__ . '/../../src/Utility/Legacy/html_helper.php';
    if (!function_exists('env')) { function env($k) { return getenv($k); } }
    if (!defined('_AUTH_VAR')) { define('_AUTH_VAR', 'av'); }
    $_SESSION[_AUTH_VAR] = new class {
        public $SessVar = ['content-type' => 'HTML'];
    };
    if (($argv[2] ?? '') === 'on') {
        putenv('GC_ENVELOPE=1');
        $_SERVER['HTTP_X_GC_ENVELOPE'] = '1';
    }
    // The FK delete-refusal call shape (goatcheese Service.php:509, $print=true).
    try {
        $ret = handleNotOkResponse("This entry cannot be deleted. It is in use in 'Contact'.", '', true, 'Company');
    } catch (\ApiGoat\Http\HaltResponse $halt) {
        // Envelope ON: stand in for the route closure and emit the halt body.
        echo $halt->getBodyText();
        return;
    }
    // Reached only when the envelope is OFF (the legacy branch returns instead
    // of halting); echo the legacy script so the parent can assert the fallback.
    if (is_array($ret) && isset($ret['onReadyJs'])) {
        echo $ret['onReadyJs'];
    }
    return;
}

function child(string $mode): string
{
    return (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($mode) . ' 2>/dev/null'
    );
}

$fail = 0;
function check(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? "PASS  " : "FAIL  ") . $label . "\n";
    if (!$cond) { $fail++; }
}

$on = child('on');
$env = json_decode(trim($on), true);
check('envelope on: valid JSON envelope', is_array($env));
check('envelope on: status = refused', ($env['status'] ?? null) === 'refused');
check('envelope on: title carried', ($env['messages'][0]['title'] ?? null) === 'Company');
check('envelope on: apostrophe preserved', strpos($env['messages'][0]['text'] ?? '', "'Contact'") !== false);

$off = child('off');
check('envelope off: legacy <script> alertb (not JSON)', strpos($off, 'alertb(') !== false && strpos(trim($off), '{') !== 0);

echo "\n" . ($fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);
