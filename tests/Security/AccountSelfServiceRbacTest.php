<?php
// Run: php tests/Security/AccountSelfServiceRbacTest.php
//
// Regression guard for the My-Account RBAC lock-out.
//
// The `/Account` page and `/api/v1/Account/...` data endpoint are self-service:
// AccountService / AccountServiceWrapper only ever read/write the logged-in
// user's OWN authy row (id from $_SESSION, never a client id). But the RBAC
// middleware derives the model from the first URL path segment, so it saw the
// non-model name "Account" and called authorize('Account', 'r') — which can
// never succeed for a non-admin (no such model in the rights matrix) and can
// never be granted. Result: every non-admin user was denied "[Account, r]" on
// their own account page. checkPrivileges() now exempts the account route from
// the model-RBAC check while still requiring authentication.
//
// This test boots the runtime autoloader and drives the private
// checkPrivileges() via reflection with a stub non-admin session, asserting:
//   1. authenticated non-admin  + model 'Account'      -> allowed (false)
//   2. authenticated non-admin  + a real model         -> still denied
//   3. UNauthenticated          + model 'Account'      -> auth still required
//   4. authenticated non-admin  + model 'ACCOUNT' (case)-> allowed (false)

// The runtime is a library with no standalone vendor/; it is exercised through
// a consuming project's autoloader. When run via phpunit the CWD-relative
// `vendor/autoload.php` (phpunit.xml bootstrap) resolves; for a standalone
// `php tests/...` run, probe the usual locations and pick the first that can
// actually load ApiGoat\ classes.
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

// Minimal PSR-7-ish request stub: checkPrivileges only ever calls getMethod().
$request = new class {
    public function getMethod() { return 'GET'; }
};

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

// 1. authenticated non-admin reaching their own account page -> ALLOWED.
$_SESSION[_AUTH_VAR] = makeSession('YES');
$r1 = run($mw, $argsProp, $method, $request,
    ['route' => 'Account', 'model' => 'Account', 'action' => 'list', 'is_api' => true]);
check('non-admin + Account page -> allowed', $r1, false);

// 2. authenticated non-admin reaching a real model they lack -> STILL DENIED.
$r2 = run($mw, $argsProp, $method, $request,
    ['route' => 'BankAccount', 'model' => 'BankAccount', 'action' => 'list', 'is_api' => true]);
check('non-admin + real model -> denied (InvalidSessionRenderer)',
    $r2 instanceof InvalidSessionRenderer, true);

// 3. UNauthenticated user hitting the account route -> AUTH STILL REQUIRED.
$_SESSION[_AUTH_VAR] = makeSession('NO');
$r3 = run($mw, $argsProp, $method, $request,
    ['route' => 'Account', 'model' => 'Account', 'action' => 'list', 'is_api' => true]);
check('unauthenticated + Account -> auth required (true)', $r3, true);

// 4. case-insensitive: api path segment may arrive as 'Account'/'account' etc.
$_SESSION[_AUTH_VAR] = makeSession('YES');
$r4 = run($mw, $argsProp, $method, $request,
    ['route' => 'account', 'model' => 'account', 'action' => 'list', 'is_api' => true]);
check('non-admin + lowercase account -> allowed', $r4, false);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
