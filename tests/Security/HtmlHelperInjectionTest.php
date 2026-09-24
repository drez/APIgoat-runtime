<?php
// Run: php tests/Security/HtmlHelperInjectionTest.php
// Attribute / JS-string breakouts in the legacy html_helper emitters.
require __DIR__ . '/../../src/Utility/Legacy/html_helper.php';

if (!defined('_AUTH_VAR')) {
    define('_AUTH_VAR', 'gc_test_auth');
}
$_SESSION[_AUTH_VAR] = (object) ['SessVar' => ['content-type' => 'HTML'], 'config' => ['locale' => ['locale' => 'en']]];

$fail = 0;
function check($l, $cond)
{
    if ($cond) { echo "PASS  $l\n"; } else { echo "FAIL  $l\n"; $GLOBALS['fail']++; }
}

// selectboxCustomArray: the selected value sits in a single-quoted data attribute.
$sb = selectboxCustomArray('Status', [['Open', 'open', ''], ['Closed', 'closed', '']], 'Status', '', "x' autofocus onfocus='alert(1)");
check('selectbox: no attribute breakout', strpos($sb, "x' autofocus") === false);
check('selectbox: value kept as JSON', preg_match("/data-default-selected='([^']*)'/", $sb, $m) === 1
    && json_decode($m[1], true) === ["x' autofocus onfocus='alert(1)"]);

// htmlLink: script-bearing schemes, however encoded, collapse to '#'.
foreach (['javascript:alert(1)', 'jav&#x61;script:alert(1)', '&#106;avascript:x', "java\tscript:x", ' JavaScript:x',
          'jav&Tab;ascript:x', 'vbscript:msgbox(1)', 'data:text/html,<script>alert(1)</script>'] as $l) {
    check("htmlLink blocks $l", htmlLink('x', $l) === '<a href="#"  >x</a>');
}
foreach (['https://x.test/a?b=1&amp;c=2', '/Quote/edit/1', '#', 'mailto:a@b.test', 'data:image/png;base64,AA'] as $l) {
    check("htmlLink keeps $l", strpos(htmlLink('x', $l), 'href="' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8', false) . '"') !== false);
}

// handleNotOkResponse: the message is a JS string inside <script>.
$payload = "a\\'); alert(1); //</script><img src=x onerror=alert(2)>";
$r = handleNotOkResponse($payload, '', true, "T'\\");
check('notOk: no </script> breakout', substr_count(strtolower($r['onReadyJs']), '</script>') === 1);
check('notOk: string literal intact', strpos($r['onReadyJs'], 'alertb(' . gcJsStr("T'\\") . ', ' . gcJsStr($payload) . ');') !== false);
$r = handleNotOkResponse($payload);
check('notOk (no print): no raw quote/tag', strpos($r['onReadyJs'], "');") === false && strpos($r['onReadyJs'], '<img') === false);

// handleValidationError
$fails = new class { function getValidationFailures() { return [new class { function getMessage() { return "bad'</script><img src=x>"; } function getColumn() { return "q.na'me"; } }]; } };
$r = handleValidationError($fails, 'ui', "T'");
check('validation: no raw tag / quote breakout', strpos($r['onReadyJs'], '<img') === false && strpos($r['onReadyJs'], '</script') === false
    && strpos($r['onReadyJs'], "NA'ME") === false);
check('validation: selector string', strpos($r['onReadyJs'], gcJsStr('#ui [v=NA\'ME]')) !== false);

// getUrlParamsJSON: request keys are attacker-controlled.
$_REQUEST = ["k'});alert(1);//" => 'v', 'ok' => 'a b'];
$js = getUrlParamsJSON();
check('urlParams: key cannot end the string', strpos($js, "k'") === false);
check('urlParams: parses as an object', json_decode('{' . str_replace("'dum':'z'", '"dum":"z"', $js) . '}', true) === ['dum' => 'z', "k'});alert(1);//" => 'v', 'ok' => 'a+b']);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
