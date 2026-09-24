<?php
// Run: php tests/Security/OAuthTokenCsrfTest.php
//
// Regression guard for the mobile PKCE token exchange dying on the CSRF gate.
//
// POST /oauth/token (and /oauth/register) are OAuth 2.1 PROTOCOL endpoints:
// they are authenticated by what is IN the request (PKCE code + verifier +
// client_id, or a refresh token) and the authorization server never reads the
// ambient PHP session as a credential there. But React Native's okhttp cookie
// jar re-sends the ApiGoat session cookie the API set on earlier calls, and if
// that session is still marked connected (a previous sign-in), checkCsrf()
// treated the token POST as a session-authenticated state change with a
// missing CSRF token -> 403 {"status":"failure",...} (no OAuth `error` key).
// expo-auth-session only throws on an `error` key, so the client built a
// TokenResponse with accessToken=undefined and crashed with "Invalid value
// provided to SecureStore" at the store step — masking the real failure.
// (Observed live: prod error log "csrf rejected: POST oauth/token ... uid=2",
// emulator repro 2026-08-20.)
//
// The fix exempts exactly the cookie-less-by-design protocol endpoints from
// the session-CSRF gate. /oauth/authorize is NOT exempt: the consent form is
// a genuine session-authenticated browser POST and keeps its csrf field.
//
// Drives the private checkCsrf() via reflection with a stub connected session:
//   1. connected session + POST oauth/token,    no token -> ALLOWED (null)
//   2. connected session + POST oauth/register, no token -> ALLOWED (null)
//   3. connected session + POST oauth/authorize, no token -> STILL 403
//   4. connected session + POST real route,      no token -> STILL 403
//   5. connected session + POST real route + valid token -> allowed
//   6. Bearer request    + POST real route,      no token -> allowed (exempt)
//   7. oauth/token must not leak: "oauth/tokenX" style route -> STILL 403

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
use Psr\Http\Message\ResponseInterface;

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

// Stub session: connected user with a known CSRF token.
function makeSession(string $connected, string $csrf = 'sess-csrf-token')
{
    return new class($connected, $csrf) {
        private $connected;
        private $csrf;
        public function __construct($c, $t) { $this->connected = $c; $this->csrf = $t; }
        public function getCsrf() { return $this->csrf; }
        public function get($k)
        {
            if ($k === 'connected') return $this->connected;
            if ($k === 'id')        return 2;
            return null;
        }
    };
}

function makeRequest(string $verb, string $authHeader = '', string $csrfHeader = '')
{
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($verb, '/');
    if ($authHeader !== '') {
        $request = $request->withHeader('Authorization', $authHeader);
    }
    if ($csrfHeader !== '') {
        $request = $request->withHeader('X-Csrf-Token', $csrfHeader);
    }
    return $request;
}

$privilegeMap = [
    'action'  => ['list' => 'r', 'view' => 'r', 'create' => 'a', 'update' => 'w', 'delete' => 'd'],
    'exclude' => ['Authy/login', 'GuiManager'],
];

$ref      = new ReflectionClass(AuthyMiddleware::class);
$mw       = $ref->newInstanceWithoutConstructor();
$pmProp   = $ref->getProperty('privilegeMap'); $pmProp->setAccessible(true); $pmProp->setValue($mw, $privilegeMap);
$argsProp = $ref->getProperty('args');         $argsProp->setAccessible(true);
$method   = $ref->getMethod('checkCsrf');      $method->setAccessible(true);

function run($mw, $argsProp, $method, $request, array $args)
{
    $argsProp->setValue($mw, $args);
    return $method->invoke($mw, $request);
}

$_SESSION[_AUTH_VAR] = makeSession('YES');

// 1. The regression: stale connected session cookie riding on the app's
//    token exchange must NOT trip the CSRF gate.
$r1 = run($mw, $argsProp, $method, makeRequest('POST'),
    ['route' => 'oauth/token', 'model' => 'oauth', 'action' => 'token', 'is_api' => true, 'data' => []]);
check('connected session + POST oauth/token, no csrf -> allowed', $r1, null);

// 2. Dynamic client registration is the same class of endpoint.
$r2 = run($mw, $argsProp, $method, makeRequest('POST'),
    ['route' => 'oauth/register', 'model' => 'oauth', 'action' => 'register', 'is_api' => true, 'data' => []]);
check('connected session + POST oauth/register, no csrf -> allowed', $r2, null);

// 3. The consent form is a genuine session-authenticated browser POST — the
//    exemption must not widen to it.
$r3 = run($mw, $argsProp, $method, makeRequest('POST'),
    ['route' => 'oauth/authorize', 'model' => 'oauth', 'action' => 'authorize', 'is_api' => true, 'data' => []]);
check('connected session + POST oauth/authorize, no csrf -> still 403',
    $r3 instanceof ResponseInterface && $r3->getStatusCode() === 403, true);

// 4. Ordinary session-authenticated writes stay protected.
$r4 = run($mw, $argsProp, $method, makeRequest('POST'),
    ['route' => 'Product', 'model' => 'Product', 'action' => 'update', 'is_api' => true, 'data' => []]);
check('connected session + POST real route, no csrf -> still 403',
    $r4 instanceof ResponseInterface && $r4->getStatusCode() === 403, true);

// 5. ... and a valid token still passes.
$r5 = run($mw, $argsProp, $method, makeRequest('POST', '', 'sess-csrf-token'),
    ['route' => 'Product', 'model' => 'Product', 'action' => 'update', 'is_api' => true, 'data' => []]);
check('connected session + POST real route + valid csrf -> allowed', $r5, null);

// 6. An AUTHENTICATED bearer carries no forgeable ambient credential.
$apiArgs = ['route' => 'Product', 'model' => 'Product', 'action' => 'update', 'is_api' => true, 'data' => []];
$r6 = run($mw, $argsProp, $method,
    makeRequest('POST', 'Bearer abc')->withAttribute(AuthyMiddleware::ATTR_BEARER_AUTH, true), $apiArgs);
check('OAuth-authenticated bearer + POST real route, no csrf -> allowed', $r6, null);
$r6b = run($mw, $argsProp, $method,
    makeRequest('POST', 'Bearer abc')->withAttribute('jwt_claims', ['authyId' => 2]), $apiArgs);
check('JwtAuthentication-verified bearer + POST, no csrf -> allowed', $r6b, null);

// 6c. Review-3 Wave 3: a junk bearer header on a connected COOKIE session is
//     NOT authentication — JwtAuthentication skipped it (already connected).
$r6c = run($mw, $argsProp, $method, makeRequest('POST', 'Bearer abc'), $apiArgs);
check('unverified bearer + connected cookie session, no csrf -> 403',
    $r6c instanceof ResponseInterface && $r6c->getStatusCode() === 403, true);

// 6d. ... but the mobile case (cookie jar kept the login session, a VALID
//     HS256 app token for the same user) stays exempt.
putenv('JWT_SECRET=csrf-test-secret-0123456789abcdef0123456789abcdef');
$good  = \Firebase\JWT\JWT::encode(['authyId' => 2, 'exp' => time() + 60], 'csrf-test-secret-0123456789abcdef0123456789abcdef', 'HS256');
$other = \Firebase\JWT\JWT::encode(['authyId' => 3, 'exp' => time() + 60], 'csrf-test-secret-0123456789abcdef0123456789abcdef', 'HS256');
$forged = \Firebase\JWT\JWT::encode(['authyId' => 2, 'exp' => time() + 60], 'not-the-project-secret-0123456789abcdef0123456789', 'HS256');
check('valid HS256 bearer for the session user -> allowed',
    run($mw, $argsProp, $method, makeRequest('POST', 'Bearer ' . $good), $apiArgs), null);
$r6e = run($mw, $argsProp, $method, makeRequest('POST', 'Bearer ' . $other), $apiArgs);
check('valid HS256 bearer for ANOTHER user on this cookie -> 403',
    $r6e instanceof ResponseInterface && $r6e->getStatusCode() === 403, true);
$r6f = run($mw, $argsProp, $method, makeRequest('POST', 'Bearer ' . $forged), $apiArgs);
check('HS256 bearer signed with another secret -> 403',
    $r6f instanceof ResponseInterface && $r6f->getStatusCode() === 403, true);
putenv('JWT_SECRET');

// 7. Exact-route match only — a lookalike route must not inherit the exemption.
$r7 = run($mw, $argsProp, $method, makeRequest('POST'),
    ['route' => 'oauth/tokenX', 'model' => 'oauth', 'action' => 'tokenX', 'is_api' => true, 'data' => []]);
check('connected session + POST oauth/tokenX, no csrf -> still 403',
    $r7 instanceof ResponseInterface && $r7->getStatusCode() === 403, true);

echo $fail ? "\n$fail FAILURES\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
