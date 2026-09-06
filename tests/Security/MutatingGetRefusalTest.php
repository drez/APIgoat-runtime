<?php
// Run: php tests/Security/MutatingGetRefusalTest.php
//
// Service::isMutatingAction / mutatingGetRefusal are pure statics, so the class
// file is loaded directly (plus the one trait it uses) rather than booting the
// app. Covers F3 (review #13): the GET exemptions are GONE — generatepdf,
// opengdrive, stripecheckout and stripecharge are refused on GET like every
// other write, now that the template client POSTs them.

require __DIR__ . '/../../src/Services/Concerns/HaltsResponses.php';
require __DIR__ . '/../../src/Services/Service.php';

use ApiGoat\Services\Service;

$fail = 0;
function check(string $label, $got, $want): void
{
    if ($got === $want) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label (got " . var_export($got, true) . ")\n";
        $GLOBALS['fail']++;
    }
}

/** Minimal PSR-7-ish stand-in: only getHeaderLine() is used. */
class FakeReq
{
    private array $h;
    public function __construct(array $h = []) { $this->h = $h; }
    public function getHeaderLine(string $n): string { return (string) ($this->h[$n] ?? ''); }
}

// ── the action inventory ─────────────────────────────────────────────────
foreach (['insert', 'update', 'delete', 'clone', 'mass', 'prune', 'quickadd',
          'upload', 'newfolder', 'childunlink', 'generatepdf', 'opengdrive',
          'stripecheckout', 'stripecharge', 'striperefund', 'chat'] as $a) {
    check("isMutatingAction({$a})", Service::isMutatingAction($a), true);
}
check('isMutatingAction is case-insensitive', Service::isMutatingAction('DELETE'), true);
check('per-child prefix NtNsave<Child>', Service::isMutatingAction('NtNsaveInvoiceLine'), true);
check('per-child prefix BUsave<Child>', Service::isMutatingAction('BUsaveInvoiceLine'), true);
foreach (['list', 'edit', 'view', 'autoc', 'search', 'printable', 'pdfdownload',
          'stripestatus', 'file', 'open', 'login', 'logout', 'confirm'] as $a) {
    check("read stays non-mutating ({$a})", Service::isMutatingAction($a), false);
}
check('empty action', Service::isMutatingAction(''), false);
check('null action', Service::isMutatingAction(null), false);

// ── the GET refusal ──────────────────────────────────────────────────────
$get = static fn (string $a, array $extra = []): array => $extra + ['method' => 'GET', 'a' => $a];

check('GET delete refused', Service::mutatingGetRefusal($get('delete'), new FakeReq()), true);
check('POST delete allowed',
    Service::mutatingGetRefusal(['method' => 'POST', 'a' => 'delete'], new FakeReq()), false);
check('GET list allowed', Service::mutatingGetRefusal($get('list'), new FakeReq()), false);
check('HEAD delete refused', Service::mutatingGetRefusal(['method' => 'HEAD', 'a' => 'delete'], new FakeReq()), true);

// F3: the four formerly-exempt actions.
foreach (['generatepdf', 'opengdrive', 'stripecheckout', 'stripecharge'] as $a) {
    check("F3: GET {$a} is now refused (plain navigation)",
        Service::mutatingGetRefusal($get($a), new FakeReq()), true);
    check("F3: GET {$a} is now refused even with X-Requested-With",
        Service::mutatingGetRefusal($get($a), new FakeReq(['X-Requested-With' => 'XMLHttpRequest'])), true);
    check("F3: POST {$a} allowed",
        Service::mutatingGetRefusal(['method' => 'POST', 'a' => $a], new FakeReq()), false);
}

// Bearer / api routes keep their exemption (no ambient cookie authority).
check('bearer GET delete allowed',
    Service::mutatingGetRefusal($get('delete'), new FakeReq(['Authorization' => 'Bearer abc.def.ghi'])), false);
check('api route GET delete allowed',
    Service::mutatingGetRefusal($get('delete', ['is_api' => true]), new FakeReq()), false);
check('isApiCall GET delete allowed',
    Service::mutatingGetRefusal($get('delete', ['isApiCall' => true]), new FakeReq()), false);
// The path-derived action can also arrive on 'action' (RouteParser::decodePath).
check("'action' key is honoured",
    Service::mutatingGetRefusal(['method' => 'GET', 'action' => 'delete'], new FakeReq()), true);

// ── F4: the bulk-edit selection decoder ──────────────────────────────────
check('bulkSelection: multi-row check_<pk>=<pk> pairs',
    Service::bulkSelection('check_1=1&check_2=2'), ['1', '2']);
check('bulkSelection: urlencoded pairs',
    Service::bulkSelection('check_1%3D1%26check_2%3D2'), ['1', '2']);
check('bulkSelection: single row',
    Service::bulkSelection('check_7=7'), ['7']);
check('bulkSelection: a BARE pk still selects it (was an empty selection)',
    Service::bulkSelection('2'), ['2']);
check('bulkSelection: idPk[] array shape',
    Service::bulkSelection('idPk[]=3&idPk[]=4'), ['3', '4']);
check('bulkSelection: empty', Service::bulkSelection(''), []);
check('bulkSelection: whitespace only', Service::bulkSelection('   '), []);
check('bulkSelection: JSON composite pk survives as the VALUE',
    Service::bulkSelection('check_a=' . rawurlencode('{"IdA":1,"IdB":2}')), ['{"IdA":1,"IdB":2}']);

// ── I-5: project-defined custom actions fail CLOSED on a cookie-auth GET ──
// MUTATING_ACTIONS only lists the case labels the emitter writes. A wrapper's
// own $customActions entry ('approveInvoice', 'sendBatch', …) is dispatched
// from the same {a} URL segment on the same GET-registered route and was NOT
// refused — fail-open for exactly the actions a project author adds by hand.
class CustomActionsService extends Service
{
    public $customActions = ['approveInvoice' => 'approve', 'agingReport' => 'aging'];
    protected array $readOnlyCustomActions = ['agingReport'];
    public function __construct() {}
}
class NoOptOutService extends Service
{
    public $customActions = ['approveInvoice' => 'approve'];
    public function __construct() {}
}

$svc  = new CustomActionsService();
$bare = new NoOptOutService();

check('custom action, cookie GET, not declared read-only → refused',
    Service::customActionGetRefusal($svc, ['method' => 'GET', 'a' => 'approveInvoice'], new FakeReq()), true);
check('custom action, cookie GET, declared read-only → allowed',
    Service::customActionGetRefusal($svc, ['method' => 'GET', 'a' => 'agingReport'], new FakeReq()), false);
check('read-only opt-out is case-insensitive',
    Service::customActionGetRefusal($svc, ['method' => 'GET', 'a' => 'AGINGREPORT'], new FakeReq()), false);
check('a service with no opt-out list refuses every custom action on GET',
    Service::customActionGetRefusal($bare, ['method' => 'GET', 'a' => 'approveInvoice'], new FakeReq()), true);
check('POST is never refused',
    Service::customActionGetRefusal($bare, ['method' => 'POST', 'a' => 'approveInvoice'], new FakeReq()), false);
check('HEAD is refused like GET',
    Service::customActionGetRefusal($bare, ['method' => 'HEAD', 'a' => 'approveInvoice'], new FakeReq()), true);
check('api/v1 (bearer, no ambient cookie) is not refused',
    Service::customActionGetRefusal($bare, ['method' => 'GET', 'a' => 'approveInvoice', 'is_api' => 1], new FakeReq()), false);
check('a Bearer header is not refused',
    Service::customActionGetRefusal($bare, ['method' => 'GET', 'a' => 'approveInvoice'],
        new FakeReq(['Authorization' => 'Bearer abc'])), false);
check('an empty action is not refused',
    Service::customActionGetRefusal($bare, ['method' => 'GET', 'a' => ''], new FakeReq()), false);
check('isReadOnlyCustomAction reflects the declaration',
    [$svc->isReadOnlyCustomAction('agingReport'), $svc->isReadOnlyCustomAction('approveInvoice')], [true, false]);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
