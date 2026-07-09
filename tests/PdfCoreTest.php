<?php
// Run: php tests/PdfCoreTest.php   (from the runtime repo root)
//
// Pure-logic coverage for the with_pdf runtime core: PdfNaming (canonical filename),
// PdfStaleness::isCurrentGiven (url/timestamp rules)
// and PdfManifest gating (drives ToolRegistry's conditional registration).

require __DIR__ . '/../src/Pdf/PdfNaming.php';
require __DIR__ . '/../src/Pdf/PdfStaleness.php';
require __DIR__ . '/../src/Pdf/PdfManifest.php';
require __DIR__ . '/../src/Pdf/PdfCurrency.php';

use ApiGoat\Pdf\PdfCurrency;
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

// ── PdfCurrency: scalar column, FK path, fallback ──────────────────────────
$scalar = new class { public function getCurrency() { return 'USD'; } };
$fkObj  = new class { public function getName() { return 'EUR'; } };
$fk     = new class($fkObj) {
    public function __construct(public object $c) {}
    public function getCurrency() { return $this->c; }
};
$empty  = new class { public function getCurrency() { return ''; } };
$none   = new class {};
check('no path → CAD fallback', PdfCurrency::resolve($scalar, []), 'CAD');
check('scalar column path', PdfCurrency::resolve($scalar, ['currency' => 'Currency']), 'USD');
check('FK getter-chain path', PdfCurrency::resolve($fk, ['currency' => 'Currency.Name']), 'EUR');
check('empty value → fallback', PdfCurrency::resolve($empty, ['currency' => 'Currency']), 'CAD');
check('missing getter → fallback', PdfCurrency::resolve($none, ['currency' => 'Nope']), 'CAD');

echo $fail === 0 ? "\nALL OK\n" : "\n{$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
