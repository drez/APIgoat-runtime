<?php
// Run: php tests/HtmlToPdfSsrfTest.php   (from the runtime repo root)
//
// SSRF guard for the dompdf allowed-protocol rule: internal / reserved hosts
// must be rejected, public hosts allowed. No dompdf needed — assertPublicUrl
// is pure.

require __DIR__ . '/../src/Pdf/HtmlToPdf.php';

use ApiGoat\Pdf\HtmlToPdf;

$fail = 0;
function check(string $label, bool $got, bool $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  $label\n";
    } else {
        $fail++;
        fwrite(STDERR, "FAIL  $label (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n");
    }
}

function allowed(string $url): bool
{
    return HtmlToPdf::assertPublicUrl($url)[0];
}

// Blocked: the classic SSRF / local targets.
check('cloud metadata 169.254.169.254',        allowed('http://169.254.169.254/latest/meta-data/'), false);
check('loopback 127.0.0.1',                     allowed('http://127.0.0.1/'), false);
check('loopback localhost',                     allowed('http://localhost/admin'), false);
check('private 10.x',                           allowed('http://10.0.0.5/'), false);
check('private 192.168.x',                      allowed('https://192.168.1.1/'), false);
check('private 172.16.x',                       allowed('http://172.16.0.1/'), false);
check('link-local 169.254.x',                   allowed('http://169.254.10.10/'), false);
check('IPv6 loopback [::1]',                     allowed('http://[::1]/'), false);
check('IPv4-mapped IPv6 private',                allowed('http://[::ffff:10.0.0.1]/'), false);
check('unparseable host',                        allowed('http:///nohost'), false);

// Allowed: public addresses / hostnames.
check('public IPv4 8.8.8.8',                     allowed('https://8.8.8.8/'), true);
check('public host example.com',                 allowed('https://example.com/logo.png'), true);

if ($fail) {
    fwrite(STDERR, "\n$fail failure(s)\n");
    exit(1);
}
echo "\nAll HtmlToPdf SSRF checks passed\n";
