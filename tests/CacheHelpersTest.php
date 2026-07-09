<?php
// Run: php tests/CacheHelpersTest.php   (from the runtime repo root)
//
// MicroCache counters + TableVersion generations/tenant token + SelectBoxCache
// key semantics + ChildCountCache guards. Runs on the per-process fallback
// (CLI without apc.enable_cli), which shares its semantics with APCu.

require __DIR__ . '/../src/Utility/MicroCache.php';
require __DIR__ . '/../src/Utility/TableVersion.php';
require __DIR__ . '/../src/Utility/SelectBoxCache.php';
require __DIR__ . '/../src/Utility/ChildCountCache.php';

use ApiGoat\Utility\ChildCountCache;
use ApiGoat\Utility\MicroCache;
use ApiGoat\Utility\SelectBoxCache;
use ApiGoat\Utility\TableVersion;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  $label\n";
    } else {
        $fail++;
        fwrite(STDERR, "FAIL  $label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n");
    }
}

/** Minimal stand-in for the AuthySession stored in $_SESSION[_AUTH_VAR]. */
class FakeSession
{
    public function __construct(private array $vals)
    {
    }
    public function get($k)
    {
        return $this->vals[$k] ?? null;
    }
}

// ---------------------------------------------------------------- MicroCache counters

MicroCache::flushLocal();
check('counter: 0 before any increment', MicroCache::counter('t:c1'), 0);
$v1 = MicroCache::increment('t:c1');
check('increment: seeds >= time() (APCu-restart safety)', $v1 >= time(), true);
$v2 = MicroCache::increment('t:c1');
check('increment: +1 on subsequent bumps', $v2, $v1 + 1);
check('counter: reads the current value', MicroCache::counter('t:c1'), $v2);
MicroCache::flushLocal();
check('flushLocal clears counters too', MicroCache::counter('t:c1'), 0);

// ---------------------------------------------------------------- TableVersion

MicroCache::flushLocal();
check('TableVersion::get is "0" before any bump', TableVersion::get('client'), '0');
TableVersion::bump('client');
$g1 = TableVersion::get('client');
check('bump changes the generation', $g1 !== '0', true);
TableVersion::bump('client');
check('second bump changes it again', TableVersion::get('client') !== $g1, true);
check('other tables unaffected', TableVersion::get('supplier'), '0');

// ---------------------------------------------------------------- tenant token
// Truth table mirrors GoatCheese.php tenantQueryGuard verbatim.

check('tenantToken: no _AUTH_VAR defined → all', TableVersion::tenantToken(), 'all');
define('_AUTH_VAR', 'gcTestAuth');
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'YES', 'isRoot' => false, 'id_tenant' => 3]);
check('tenantToken: connected non-root tenant → t3', TableVersion::tenantToken(), 't3');
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'YES', 'isRoot' => true, 'id_tenant' => 3]);
check('tenantToken: root → all (sees every tenant)', TableVersion::tenantToken(), 'all');
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'YES', 'isRoot' => false, 'id_tenant' => 0]);
check('tenantToken: falsy id_tenant → all (guard does not filter)', TableVersion::tenantToken(), 'all');
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'NO', 'isRoot' => false, 'id_tenant' => 3]);
check('tenantToken: not connected → all', TableVersion::tenantToken(), 'all');
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'YES', 'isRoot' => false, 'id_tenant' => 3]);

// ---------------------------------------------------------------- SelectBoxCache

putenv('GC_SELECTBOX_CACHE_TTL=60');
MicroCache::flushLocal();
$opts = [['Acme', 1], ['Zeta', 2]];
check('fetch: miss before store', SelectBoxCache::fetch('client', 'Billing_IdClient', false), null);
SelectBoxCache::store('client', 'Billing_IdClient', false, $opts);
check('fetch: hit after store', SelectBoxCache::fetch('client', 'Billing_IdClient', false), $opts);
TableVersion::bump('client');
check('fetch: miss after the FK table generation bumps (write-through)',
    SelectBoxCache::fetch('client', 'Billing_IdClient', false), null);

// Tenant-scoped keys split per tenant; unscoped tables share one entry.
SelectBoxCache::store('client', 'Billing_IdClient', true, $opts); // as tenant t3
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'YES', 'isRoot' => false, 'id_tenant' => 4]);
check('fetch: tenant t4 does not see t3\'s tenant-scoped entry',
    SelectBoxCache::fetch('client', 'Billing_IdClient', true), null);
$_SESSION[_AUTH_VAR] = new FakeSession(['connected' => 'YES', 'isRoot' => false, 'id_tenant' => 3]);
check('fetch: tenant t3 sees its own entry',
    SelectBoxCache::fetch('client', 'Billing_IdClient', true), $opts);

putenv('GC_SELECTBOX_CACHE_TTL=0');
check('ttl 0: fetch disabled', SelectBoxCache::fetch('client', 'Billing_IdClient', true), null);
SelectBoxCache::store('client', 'Other_Method', true, $opts); // no-op
putenv('GC_SELECTBOX_CACHE_TTL=60');
check('ttl 0: store was a no-op', SelectBoxCache::fetch('client', 'Other_Method', true), null);
putenv('GC_SELECTBOX_CACHE_TTL');

// ---------------------------------------------------------------- ChildCountCache

check('ChildCountCache: unknown child Query class → null (no badge)',
    ChildCountCache::count('DefinitelyMissingModel', 'IdParent', 7), null);

// ----------------------------------------------------------------

if ($fail) {
    fwrite(STDERR, "\n$fail failure(s)\n");
    exit(1);
}
echo "\nAll cache-helper checks passed\n";
