<?php
// Run: php tests/Security/HtmlEscapingTest.php
require __DIR__ . '/../../src/Utility/Legacy/html_helper.php';

$fail = 0;
function check($l, $cond)
{
    global $fail;
    if ($cond) { echo "PASS  $l\n"; } else { echo "FAIL  $l\n"; $GLOBALS['fail']++; }
}

$payload = '"><img src=x onerror=alert(1)>';
$options = [[$payload, '7', 'v1']];   // [label, value, v]

$out = optionListeSelect($options, null, '');
$html = is_array($out) ? ($out['optionsList'] ?? '') : (string) $out;
check('optionListeSelect: no raw <img', strpos($html, '<img') === false);
check('optionListeSelect: entity-escaped', strpos($html, '&lt;img') !== false || strpos($html, '&quot;') !== false);

$opt = option($payload, '7');
check('option(): no raw <img', strpos($opt, '<img') === false);

$json = arrayToJson($options, '');
check('arrayToJson: no raw <img', strpos($json, '<img') === false);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
