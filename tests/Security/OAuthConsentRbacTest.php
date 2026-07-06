<?php
// Run: php tests/Security/OAuthConsentRbacTest.php
//
// Regression guard for the OAuth-consent RBAC lock-out (mobile PKCE sign-in).
//
// GET/POST /oauth/authorize is the consent step of the PKCE flow: an
// authenticated user approves a client's access to their OWN account, and the
// issued token is later authorized per-operation (api_rbac + AuthyMiddleware +
// Api::authorize + ACL) exactly like a browser session. But the RBAC middleware
// derives the model from the first URL path segment, so it saw the non-model
// name "oauth" and called authorize('oauth', 'r'|'w') — which can never succeed
// for a non-admin (no such model in the rights matrix) and can never be
// granted. Result: only Admin-group users could complete the mobile app
// sign-in; everyone else was denied "[oauth, r]" / "[oauth, w]" at the consent
// screen. checkPrivileges() now exempts the oauth route from the model-RBAC
// check while still requiring authentication (same rationale as /Account).
//
// This test boots the runtime autoloader and drives the private
// checkPrivileges() via reflection with a stub non-admin session, asserting:
//   1. authenticated non-admin  + model 'oauth' GET      -> allowed (false)
//   2. authenticated non-admin  + model 'oauth' POST     -> allowed (false)
//   3. UNauthenticated          + model 'oauth'          -> auth still required
//   4. authenticated non-admin  + a real model           -> still denied

(function () {
    $candidates = [
        getcwd() . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../../../../autoload.php', // installed under a project's vendor/
    ];
    foreach (glob('/var/www/gc/p/*/.admin/vendor/autoload.php') ?: [] as $p) {
        $candidates[] = $p;
    }
    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require $autoload;
            if (class_exists(\ApiGoat\Middlewares\AuthyMiddleware::class)) {
                return;
            }
        }
    }
    fwrite(STDERR, "Cannot locate an autoloader that resolves ApiGoat\\ — run from a project root.\n");
    exit(2);
})();

use ApiGoat\Middlewares\AuthyMiddleware;
use ApiGoat\Handlers\InvalidSessionRenderer;

if (!defined('_AUTH_VAR')) {
    define('_AUTH_VAR', 'AUTH');
}

$fail = 0;
function check($label, $got, $want)
{
    global $fail;
    if ($got === $want) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label (got " . var_export($got, true) . ", want " . var_export($want, true) . ")\n";
        $GLOBALS['fail']++;
    }
}

// Stub session: a connected, non-admin, non-root user with no granted rights.
function makeSession(string $connected)
{
    return new class($connected) {
        private $connected;
        public function __construct($c) { $this->connected = $c; }
        public function isAdmin() { return false; }
        public function hasRights($m = '', $r = '') { return false; }
        public function get($k)
        {
            if ($k === 'connected') return $this->connected;
            if ($k === 'isRoot')    return false;
            return null;
        }
    };
}

function makeRequest(string $verb)
{
    return new class($verb) {
        private $verb;
        public function __construct($v) { $this->verb = $v; }
        public function getMethod() { return $this->verb; }
    };
}

$privilegeMap = [
    'action'  => ['list' => 'r', 'view' => 'r', 'create' => 'a', 'update' => 'w', 'delete' => 'd'],
    'exclude' => ['Authy/login', 'GuiManager'],
];

$ref      = new ReflectionClass(AuthyMiddleware::class);
$mw       = $ref->newInstanceWithoutConstructor();
$pmProp   = $ref->getProperty('privilegeMap'); $pmProp->setAccessible(true); $pmProp->setValue($mw, $privilegeMap);
$argsProp = $ref->getProperty('args');         $argsProp->setAccessible(true);
$method   = $ref->getMethod('checkPrivileges'); $method->setAccessible(true);

function run($mw, $argsProp, $method, $request, array $args)
{
    $argsProp->setValue($mw, $args);
    return $method->invoke($mw, $request);
}

// 1. authenticated non-admin loading the consent screen (GET authorize) -> ALLOWED.
$_SESSION[_AUTH_VAR] = makeSession('YES');
$r1 = run($mw, $argsProp, $method, makeRequest('GET'),
    ['route' => 'oauth/authorize', 'model' => 'oauth', 'action' => 'authorize', 'is_api' => true]);
check('non-admin + GET oauth/authorize -> allowed', $r1, false);

// 2. authenticated non-admin submitting consent (POST authorize) -> ALLOWED.
$r2 = run($mw, $argsProp, $method, makeRequest('POST'),
    ['route' => 'oauth/authorize', 'model' => 'oauth', 'action' => 'authorize', 'is_api' => true]);
check('non-admin + POST oauth/authorize -> allowed', $r2, false);

// 3. UNauthenticated user hitting the oauth route -> AUTH STILL REQUIRED
//    (process() then routes them to the login form via its oauth carve-out).
$_SESSION[_AUTH_VAR] = makeSession('NO');
$r3 = run($mw, $argsProp, $method, makeRequest('GET'),
    ['route' => 'oauth/authorize', 'model' => 'oauth', 'action' => 'authorize', 'is_api' => true]);
check('unauthenticated + oauth -> auth required (true)', $r3, true);

// 4. authenticated non-admin reaching a real model they lack -> STILL DENIED.
$_SESSION[_AUTH_VAR] = makeSession('YES');
$r4 = run($mw, $argsProp, $method, makeRequest('GET'),
    ['route' => 'Product', 'model' => 'Product', 'action' => 'list', 'is_api' => true]);
check('non-admin + real model -> denied (InvalidSessionRenderer)',
    $r4 instanceof InvalidSessionRenderer, true);

// 5. authenticated non-admin reading the catalog (GET /api/v1/_meta) -> ALLOWED.
//    MetaService filters entities/screens/menu to the user's rights, so the
//    per-user filter (not a model right on the "_meta" segment) is the boundary.
$r5 = run($mw, $argsProp, $method, makeRequest('GET'),
    ['route' => '_meta', 'model' => '_meta', 'action' => 'list', 'is_api' => true]);
check('non-admin + GET _meta -> allowed', $r5, false);

// 6. UNauthenticated + _meta -> auth still required.
$_SESSION[_AUTH_VAR] = makeSession('NO');
$r6 = run($mw, $argsProp, $method, makeRequest('GET'),
    ['route' => '_meta', 'model' => '_meta', 'action' => 'list', 'is_api' => true]);
check('unauthenticated + _meta -> auth required (true)', $r6, true);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
