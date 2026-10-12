<?php
// Run: php tests/Builder/RenderLoginNoWarningTest.php
//
// apigoatacc monthly report 2026-10: the #1 prod error signature (5786/month)
// was `Undefined array key "js"` in BuilderLayout::renderLogin — the login
// form content carries only html + onReadyJs, and renderLogin read
// $content['js'] / $content['onReadyJs'] unguarded. renderXHR / render already
// use `?? ''`; renderLogin must tolerate the same partial shape warning-free.

require __DIR__ . '/../../src/Utility/Legacy/html_helper.php';
require __DIR__ . '/../../src/Utility/BuilderLayout.php';

$rc = new ReflectionClass('ApiGoat\\Utility\\BuilderLayout');
$bl = $rc->newInstanceWithoutConstructor();
// Skip initHeader() (needs config/assets.php + Settings); the shape under test
// is the content-array read, not the header.
foreach (['headerBuilt' => true, 'htmlHeader' => ''] as $p => $v) {
    $prop = $rc->getProperty($p);
    $prop->setAccessible(true);
    $prop->setValue($bl, $v);
}

$fail = 0;
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$cases = [
    'html+onReadyJs (login shape)' => ['html' => '<div id="logMeContainer"></div>', 'onReadyJs' => 'login_ready = true;'],
    'html only'                    => ['html' => '<div></div>'],
    'full shape'                   => ['html' => '<div></div>', 'js' => '<script></script>', 'onReadyJs' => 'x();'],
];
foreach ($cases as $label => $content) {
    try {
        $out = $bl->renderLogin($content);
        $ok = is_string($out) && strpos($out, $content['html']) !== false;
        echo ($ok ? "PASS  " : "FAIL  ") . "renderLogin $label renders without warning\n";
        if (!$ok) { $fail++; }
    } catch (ErrorException $e) {
        echo "FAIL  renderLogin $label: " . $e->getMessage() . " @ line " . $e->getLine() . "\n";
        $fail++;
    }
}
restore_error_handler();
exit($fail ? 1 : 0);
