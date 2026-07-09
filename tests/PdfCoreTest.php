<?php
// Run: php tests/PdfCoreTest.php   (from the runtime repo root)
//
// Pure-logic coverage for the with_pdf runtime core: PdfNaming (canonical +
// _bak<N> backup naming), PdfStaleness::isCurrentGiven (url/timestamp rules)
// and PdfManifest gating (drives ToolRegistry's conditional registration).

require __DIR__ . '/../src/Pdf/PdfNaming.php';
require __DIR__ . '/../src/Pdf/PdfStaleness.php';
require __DIR__ . '/../src/Pdf/PdfManifest.php';

use ApiGoat\Pdf\PdfManifest;
use ApiGoat\Pdf\PdfNaming;
use ApiGoat\Pdf\PdfStaleness;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label — got " . var_export($got, true) . " want " . var_export($want, true) . "\n";
    }
}

// ── PdfNaming: canonical filename ──────────────────────────────────────────
check('filename business+number', PdfNaming::filename('Acme Inc', null, 'B-334'), 'Acme-Inc-B-334.pdf');
check('filename falls back to contact', PdfNaming::filename('', 'John Doe', '7'), 'John-Doe-7.pdf');
check('filename all empty → document', PdfNaming::filename('', '', ''), 'document.pdf');
check('fromTemplate slugs + ensures ext', PdfNaming::fromTemplate('Bill 334 Assemblage Expert'), 'Bill-334-Assemblage-Expert.pdf');
check('fromTemplate strips existing .pdf', PdfNaming::fromTemplate('Bill-1.pdf'), 'Bill-1.pdf');

// ── PdfNaming: backup naming (replace-in-place model) ─────────────────────
check('first backup is _bak1', PdfNaming::nextBackupName('Bill-334.pdf', ['Bill-334.pdf']), 'Bill-334_bak1.pdf');
check('backup increments past max', PdfNaming::nextBackupName('Bill-334.pdf', ['Bill-334.pdf', 'Bill-334_bak1.pdf', 'Bill-334_bak3.pdf']), 'Bill-334_bak4.pdf');
check('other docs never collide', PdfNaming::nextBackupName('Bill-334.pdf', ['Bill-3340_bak9.pdf', 'Other_bak2.pdf']), 'Bill-334_bak1.pdf');
check('isBackupOf matches', PdfNaming::isBackupOf('Bill-334.pdf', 'Bill-334_bak2.pdf'), true);
check('isBackupOf rejects canonical', PdfNaming::isBackupOf('Bill-334.pdf', 'Bill-334.pdf'), false);
check('isBackupOf rejects prefix collision', PdfNaming::isBackupOf('Bill-334.pdf', 'Bill-3340_bak2.pdf'), false);

// ── PdfStaleness::isCurrentGiven matrix ────────────────────────────────────
check('no stored url never current', PdfStaleness::isCurrentGiven('', null, null), false);
check('no timestamp signal → current', PdfStaleness::isCurrentGiven('u', null, null), true);
check('unknown savedAt cannot be proven', PdfStaleness::isCurrentGiven('u', '2026-07-01 10:00:00', null), false);
check('edited after save → stale', PdfStaleness::isCurrentGiven('u', '2026-07-02 09:00:00', '2026-07-01 10:00:00'), false);
check('edited before save → current', PdfStaleness::isCurrentGiven('u', '2026-07-01 09:59:59', '2026-07-01 10:00:00'), true);
check('same-second bookkeeping stays current', PdfStaleness::isCurrentGiven('u', '2026-07-01 10:00:00', '2026-07-01 10:00:00'), true);

// ── PdfManifest gating ─────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/gc-pdf-test-' . getmypid();
@mkdir($dir . '/config/Built', 0775, true);
define('_BASE_DIR', $dir . '/');
PdfManifest::reset();
check('no manifest → unavailable', PdfManifest::available(), false);
file_put_contents($dir . '/config/Built/pdf.php', "<?php return ['billing' => ['entity' => 'Billing', 'table' => 'billing']];");
PdfManifest::reset();
check('manifest → available', PdfManifest::available(), true);
check('entry by table', PdfManifest::entry('billing')['entity'] ?? null, 'Billing');
check('entry by entity name', PdfManifest::entryFor('Billing')['table'] ?? null, 'billing');
check('unknown entry null', PdfManifest::entry('nope'), null);
unlink($dir . '/config/Built/pdf.php');

echo $fail === 0 ? "\nALL OK\n" : "\n{$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
