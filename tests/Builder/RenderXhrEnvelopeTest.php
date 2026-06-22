<?php
// Run: php tests/Builder/RenderXhrEnvelopeTest.php
//
// Pins the S4 JSON-envelope contract on BuilderLayout::renderXHR (#23):
//  - opt-in + off by default (GC_ENVELOPE unset → legacy, even with the client flag);
//  - enabled (GC_ENVELOPE=1) only serves the envelope to clients that send
//    X-GC-Envelope (stale clients still get the legacy <script> body);
//  - the legacy <script> output is byte-identical when the envelope is off
//    (no regression to existing clients);
//  - the envelope is correctly derived from the existing $content for the five
//    save/update/delete flows (incl. a fr apostrophe surviving stripslashes).

require __DIR__ . '/../../src/Utility/Legacy/html_helper.php'; // scriptReady()
require __DIR__ . '/../../src/Utility/BuilderLayout.php';
if (!function_exists('env')) {
    function env($k) { return getenv($k); }
}

$bl = (new ReflectionClass('ApiGoat\\Utility\\BuilderLayout'))->newInstanceWithoutConstructor();

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    $ok = $got === $want;
    echo ($ok ? "PASS  " : "FAIL  ") . $label
        . ($ok ? "" : "  (got " . var_export($got, true) . ", want " . var_export($want, true) . ")") . "\n";
    if (!$ok) { $GLOBALS['fail']++; }
}
$isEnvelope = function ($s) { return is_string($s) && strlen($s) && $s[0] === '{'; };

$flows = [
    'F1 save-success'   => ['error' => 'no',  'html' => '', 'js' => '', 'onReadyJs' => "sw_message('" . addslashes('Saved') . "');"],
    'F2 validation'     => ['error' => 'yes', 'html' => '', 'js' => '', 'onReadyJs' => "[v=Name]error_field; alertb('Form field','Name is required');"],
    'F3 refusal-fr'     => ['error' => 'yes', 'html' => '', 'js' => '', 'onReadyJs' => "alertb('Alerte','" . addslashes("Enregistrement de l'élément impossible") . "');"],
    'F4 delete-ok'      => ['error' => 'no',  'html' => '', 'js' => '', 'onReadyJs' => "sw_message('Deleted');"],
    'F5 delete-refusal' => ['error' => 'yes', 'html' => '', 'js' => '', 'onReadyJs' => "alertb('Cannot delete','Record in use');"],
];

// --- Gating matrix ---
$_SERVER['HTTP_X_GC_ENVELOPE'] = '1';
putenv('GC_ENVELOPE'); // unset → default off
check('default off: client flag still gets legacy', $isEnvelope($bl->renderXHR($flows['F1 save-success'])), false);

putenv('GC_ENVELOPE=1');
check('enabled + flag: gets envelope', $isEnvelope($bl->renderXHR($flows['F1 save-success'])), true);

unset($_SERVER['HTTP_X_GC_ENVELOPE']);
check('enabled + no flag (stale client): legacy', $isEnvelope($bl->renderXHR($flows['F1 save-success'])), false);

// --- Legacy regression: byte-identical when envelope off ---
putenv('GC_ENVELOPE');
foreach ($flows as $name => $c) {
    $legacy = $c['html'] . ($c['js'] ?? '') . scriptReady(trim($c['onReadyJs']));
    check("legacy byte-identical: $name", $bl->renderXHR($c), $legacy);
}

// --- Envelope translation (enabled + flag) ---
putenv('GC_ENVELOPE=1');
$_SERVER['HTTP_X_GC_ENVELOPE'] = '1';
$decode = function ($c) use ($bl) { return json_decode($bl->renderXHR($c), true); };

$e1 = $decode($flows['F1 save-success']);
check('F1 status ok', $e1['status'], 'ok');

$e2 = $decode($flows['F2 validation']);
check('F2 status error', $e2['status'], 'error');
check('F2 field Name', $e2['fields'][0]['name'], 'Name');

$e3 = $decode($flows['F3 refusal-fr']);
check('F3 status refused', $e3['status'], 'refused');
check('F3 apostrophe preserved', $e3['messages'][0]['text'], "Enregistrement de l'élément impossible");

$e5 = $decode($flows['F5 delete-refusal']);
check('F5 status refused', $e5['status'], 'refused');
check('F5 message', $e5['messages'][0]['text'], 'Record in use');

putenv('GC_ENVELOPE');
echo "\n" . ($fail === 0 ? "ALL PASS\n" : "$fail FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);
