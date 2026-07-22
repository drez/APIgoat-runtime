<?php
// Run: php tests/RouteHelperTrustedArgsTest.php   (from the runtime repo root)
//
// SECURITY REGRESSION GUARD for RouteHelper::reassertTrustedArgs().
//
// Real incident (2026-07-19..22): reassertTrustedArgs() was overwriting
// $args['method'] with the RAW HTTP method after merging user query/body params.
// Auth routes (config/routes.php) set method='AUTH' + a='confirm' via setArgs()
// AFTER construction but BEFORE getArgs(); the overwrite discarded that trusted
// override, so AuthyService::getApiResponse() dispatched `case 'GET'` (generic
// Api::getJson table read) instead of `case 'AUTH' -> confirm()`. Since
// Authy/confirm/GET is legitimately rbac.public (email-activation link), the
// generic read served the WHOLE authy table (emails, is_root, password hashes,
// rights maps, GPS, socials) with NO authentication. Same regression broke ALL
// login (auth() never ran -> 400 "Permission denied").
//
// The fix snapshots method + a immediately BEFORE the user array_merge (the
// route's trusted values, or a normal route's HTTP method + path action) and
// restores them in reassertTrustedArgs(). A client's ?method= / ?a= is
// discarded; the route-layer override survives.
//
// These tests drive getArgs() (via getGETArgs/getPOSTArgs) with reflection to
// bypass the Slim RouteContext-heavy constructor, using lightweight PSR-request
// and Route fakes, and assert on the final getArgs() output.

require __DIR__ . '/../src/Routes/RouteHelper.php';

use ApiGoat\Routes\RouteHelper;

$fail = 0;
function check(string $label, $got, $want): void
{
    global $fail;
    if ($got === $want) {
        echo "  ok  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$label}\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
}

// --- Fakes ---------------------------------------------------------------
class FakeUri
{
    private $p;
    public function __construct($p) { $this->p = $p; }
    public function getPath() { return $this->p; }
}

class FakeRequest
{
    public $query;
    public $body;
    public $attrs;
    public $uriPath;
    public function __construct($query = [], $body = [], $attrs = [], $uriPath = '/api/v1/Authy/confirm')
    {
        $this->query   = $query;
        $this->body    = $body;
        $this->attrs   = $attrs;
        $this->uriPath = $uriPath;
    }
    public function getQueryParams() { return $this->query; }
    public function getParsedBody() { return $this->body; }
    public function getAttribute($n, $d = null) { return array_key_exists($n, $this->attrs) ? $this->attrs[$n] : $d; }
    public function getUri() { return new FakeUri($this->uriPath); }
    public function getMethod() { return 'GET'; }
}

class FakeRoute
{
    private $n;
    public function __construct($n) { $this->n = $n; }
    public function getName() { return $this->n; }
}

/**
 * Build a RouteHelper positioned exactly as it is right after the route layer
 * has run the constructor + any setArgs() overrides, i.e. just before getArgs().
 * $args is the pre-merge $this->args state; the fake request carries the
 * user-supplied query/body that getArgs() will merge in.
 */
function makeHelper(string $httpMethod, string $baseRouteName, array $args, FakeRequest $request): RouteHelper
{
    $ref = new ReflectionClass(RouteHelper::class);
    $h   = $ref->newInstanceWithoutConstructor();
    $set = function (string $prop, $val) use ($ref, $h) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($h, $val);
    };
    $set('request', $request);
    $set('route', new FakeRoute($baseRouteName));
    $set('method', $httpMethod);
    $set('baseRouteName', $baseRouteName);
    $set('routeName', null);
    $set('args', $args);
    return $h;
}

// A trusted rbac_public attribute value the middleware would have resolved.
$rbacAttr = ['Authy/confirm/GET' => 'allow'];

// -------------------------------------------------------------------------
// 1. AUTH route (Authy/confirm): route sets method='AUTH', a='confirm'.
//    Attacker query ?method=GET&a=list&rbac_public=passed must NOT win.
$req  = new FakeRequest(
    ['method' => 'GET', 'a' => 'list', 'rbac_public' => 'passed', 'validationKey' => 'x'],
    [],
    ['rbac_public' => $rbacAttr]
);
$args = ['a' => 'confirm', 'method' => 'AUTH', 'data' => []];
$out  = makeHelper('GET', 'api/Authy', $args, $req)->getArgs();

check('AUTH override survives merged ?method=GET', $out['method'], 'AUTH');
check("route a='confirm' survives merged ?a=list", $out['a'], 'confirm');
check('rbac_public restored from request attribute, not ?rbac_public=passed', $out['rbac_public'], $rbacAttr);
check('resolved route name wins (p)', $out['p'], 'Authy');
check('isApiCall reasserted true', $out['isApiCall'], true);

// -------------------------------------------------------------------------
// 2. AUTH route over POST (Authy/register): body {method:GET, a:list} must NOT win.
$req  = new FakeRequest([], ['method' => 'GET', 'a' => 'list'], ['rbac_public' => $rbacAttr], '/api/v1/Authy/register');
$args = ['a' => 'register', 'method' => 'AUTH', 'data' => [], 'params' => ''];
$out  = makeHelper('POST', 'api/Authy', $args, $req)->getArgs();

check('AUTH override survives merged body method=GET (POST)', $out['method'], 'AUTH');
check("route a='register' survives merged body a=list (POST)", $out['a'], 'register');

// -------------------------------------------------------------------------
// 3. Normal GET route (no override): method must resolve to GET, and a
//    client's ?method=POST / ?a=delete must NOT override the path-derived values.
$req  = new FakeRequest(['method' => 'POST', 'a' => 'delete'], [], ['rbac_public' => null], '/api/v1/Project');
$args = ['a' => 'list', 'method' => 'GET', 'data' => []];
$out  = makeHelper('GET', 'api/Project', $args, $req)->getArgs();

check('normal GET route still resolves method=GET', $out['method'], 'GET');
check('normal route path a=list survives merged ?a=delete', $out['a'], 'list');
check('rbac_public restored (null attr) not ?rbac_public', $out['rbac_public'], null);

// -------------------------------------------------------------------------
// 4. Query-driven route (GuiManager?a=alive): the route pins NO action — empty
//    pre-merge 'a' (no setArgs('a'), no path {a} placeholder) — so the query 'a'
//    IS the real action and must SURVIVE the merge. Reasserting an empty
//    pre-merge 'a' here would blank ?a=alive and break the admin GUI keepalive
//    (GuiManager::set() dispatches on $args['a']). Safe: routes with the AUTH
//    dispatch gadget ($this->{a}()) always pin a non-empty 'a' (cases 1/2), so
//    they stay protected; empty-pre-merge routes dispatch via explicit-case
//    switches, not a dynamic method call.
$req  = new FakeRequest(['a' => 'alive'], [], ['rbac_public' => null], '/GuiManager');
$args = ['a' => '', 'method' => 'GET', 'data' => []];
$out  = makeHelper('GET', 'GuiManager', $args, $req)->getArgs();

check('GuiManager query a=alive survives (empty pre-merge a)', $out['a'], 'alive');

// -------------------------------------------------------------------------
// 5. Regression re-guard: a route that DOES pin its action (path {a} or setArgs)
//    still discards a conflicting ?a= — the AUTH gadget and admin Model/{a}
//    actions can never be redirected by the client.
$req  = new FakeRequest(['a' => 'delete'], [], ['rbac_public' => null], '/api/v1/Product');
$args = ['a' => 'edit', 'method' => 'GET', 'data' => []];
$out  = makeHelper('GET', 'api/Product', $args, $req)->getArgs();

check('path a=edit survives merged ?a=delete (non-empty pre-merge a locked)', $out['a'], 'edit');

echo $fail === 0 ? "PASS: RouteHelper trusted-arg preservation OK\n" : "FAILED: {$fail}\n";
exit($fail === 0 ? 0 : 1);
