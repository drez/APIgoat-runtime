<?php
// Run: php tests/Security/UploadExtensionTest.php
// Mirrors the extension allowlist the emitter injects into upload handlers
// (goatcheese Parameters/is_file_upload_table.php). Keep the two lists in sync.
function gc_upload_ext_allowed($ext)
{
    static $allow = ['jpg','jpeg','png','gif','webp','bmp','svgz','pdf','doc','docx',
                     'xls','xlsx','ppt','pptx','csv','txt','zip','odt','ods'];
    $ext = strtolower((string) $ext);
    return in_array($ext, $allow, true);
}

$fail = 0;
function check($l, $g, $w)
{
    global $fail;
    if ($g === $w) { echo "PASS  $l\n"; } else { echo "FAIL  $l\n"; $GLOBALS['fail']++; }
}

check('jpg allowed',    gc_upload_ext_allowed('jpg'),  true);
check('pdf allowed',    gc_upload_ext_allowed('PDF'),  true);
check('php blocked',    gc_upload_ext_allowed('php'),  false);
check('phtml blocked',  gc_upload_ext_allowed('phtml'), false);
check('phar blocked',   gc_upload_ext_allowed('phar'), false);
check('htaccess block', gc_upload_ext_allowed('htaccess'), false);
check('html blocked',   gc_upload_ext_allowed('html'), false);
check('svg blocked',    gc_upload_ext_allowed('svg'),  false); // inline-renderable -> XSS; allow svgz only
check('empty blocked',  gc_upload_ext_allowed(''),     false);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
