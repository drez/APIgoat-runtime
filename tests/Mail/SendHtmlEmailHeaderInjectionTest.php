<?php
// Run: php tests/Mail/SendHtmlEmailHeaderInjectionTest.php
// sendHTMLemail() splices from / reply / bcc into raw header lines: CR/LF
// must refuse the send before mail() is ever reached.
require_once __DIR__ . '/../../src/Utility/Legacy/html_helper.php';
require_once __DIR__ . '/../../src/Utility/Legacy/std_function.php';

$fail = 0;
function refused(string $label, bool $got): void
{
    if ($got === false) { echo "  ok   $label\n"; } else { echo "  FAIL $label\n"; $GLOBALS['fail']++; }
}

// A fake sendmail that records every delivery (sendmail_path is INI_SYSTEM,
// so re-run this script under -d): nothing refused may reach it.
$log = sys_get_temp_dir() . '/gc-sendmail-test.log';
if (ini_get('sendmail_path') !== 'cat >> ' . $log . ' #') {
    @unlink($log);
    passthru(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('sendmail_path=cat >> ' . $log . ' #') . ' ' . escapeshellarg(__FILE__), $rc);
    exit($rc);
}
refused('CRLF in reply', sendHTMLemail('<p>x</p>', 'a@example.com', 'b@example.com', 's', "r@example.com\r\nBcc: victim@example.com"));
refused('LF in bcc', sendHTMLemail('<p>x</p>', 'a@example.com', 'b@example.com', 's', '', [], "x@example.com\nX-Evil: 1"));
refused('CRLF in from', sendHTMLemail('<p>x</p>', "a@example.com\r\nBcc: v@example.com", 'b@example.com', 's'));
refused('CRLF in subject', sendHTMLemail('<p>x</p>', 'a@example.com', 'b@example.com', "s\r\nBcc: v@example.com"));
refused('invalid bare reply', sendHTMLemail('<p>x</p>', 'a@example.com', 'b@example.com', 's', 'not an email'));
refused('invalid bare bcc', sendHTMLemail('<p>x</p>', 'a@example.com', 'b@example.com', 's', '', [], 'nope'));

if (is_file($log)) { echo "  FAIL sendmail received: " . file_get_contents($log) . "\n"; $fail++; }
// Control: a clean send does reach it (proves the fake sendmail is wired).
$ok = sendHTMLemail('<p>x</p>', 'a@example.com', 'b@example.com', 's', 'Support <r@example.com>');
if (!$ok || !is_file($log)) { echo "  FAIL clean send did not go out\n"; $fail++; } else { echo "  ok   clean send goes out\n"; }
@unlink($log);

echo $fail ? "\nFAILED ($fail)\n" : "\nOK\n";
exit($fail ? 1 : 0);
