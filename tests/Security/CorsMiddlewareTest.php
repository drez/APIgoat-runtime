<?php
// Hermetic behaviour test for ApiGoat\Middlewares\CorsMiddleware.
// Runs WITHOUT a web server (so it is not polluted by Apache's CORS overlay)
// and without a database. PSR-7 impls come from a built project's vendor tree.
//
// Run from a project that has slim/psr7 installed, e.g.:
//   php vendor/apigoat/runtime/tests/Security/CorsMiddlewareTest.php /var/www/gc/p/apigoatacc/.admin/vendor/autoload.php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$autoload = $argv[1] ?? '/var/www/gc/p/apigoatacc/.admin/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "autoload not found: $autoload\n");
    exit(2);
}
require $autoload;
// Force the SOURCE class under test (not the project's possibly-stale installed copy).
require_once __DIR__ . '/../../src/Middlewares/CorsMiddleware.php';

$fail = 0;
function check(string $label, bool $cond): void
{
    if ($cond) { echo "PASS  $label\n"; } else { echo "FAIL  $label\n"; $GLOBALS['fail']++; }
}

$responseFactory = new ResponseFactory();
$mw = new \ApiGoat\Middlewares\CorsMiddleware($responseFactory);
$reqFactory = new ServerRequestFactory();

// --- Test 1: OPTIONS preflight is short-circuited BEFORE the handler runs ---
$throwingHandler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('handler must NOT be called for an OPTIONS preflight');
    }
};
$preflight = $reqFactory->createServerRequest('OPTIONS', 'https://gc.local/test/.admin/api/v1/Authy/auth')
    ->withHeader('Origin', 'https://evil.example.com')
    ->withHeader('Access-Control-Request-Method', 'POST')
    ->withHeader('Access-Control-Request-Headers', 'Content-Type, X-Csrf-Token, Authorization');

$pre = null;
try {
    $pre = $mw->process($preflight, $throwingHandler);
    check('preflight: short-circuits without calling handler', true);
} catch (\Throwable $e) {
    check('preflight: short-circuits without calling handler — ' . $e->getMessage(), false);
}

if ($pre instanceof ResponseInterface) {
    check('preflight: 2xx status (got ' . $pre->getStatusCode() . ')',
        $pre->getStatusCode() >= 200 && $pre->getStatusCode() < 300);
    check('preflight: Allow-Origin is * (NOT reflected)',
        $pre->getHeaderLine('Access-Control-Allow-Origin') === '*');
    check('preflight: NO Allow-Credentials header',
        !$pre->hasHeader('Access-Control-Allow-Credentials'));
    check('preflight: Allow-Methods present',
        $pre->getHeaderLine('Access-Control-Allow-Methods') !== '');
    check('preflight: Allow-Headers includes X-Csrf-Token',
        stripos($pre->getHeaderLine('Access-Control-Allow-Headers'), 'X-Csrf-Token') !== false);
    check('preflight: Max-Age set',
        $pre->getHeaderLine('Access-Control-Max-Age') === '20');
    check('preflight: empty body',
        (string) $pre->getBody() === '');
}

// --- Test 2: actual request — headers appended, credentials stripped, NO RouteContext needed ---
// The handler sets a hostile Allow-Credentials:true to prove the middleware strips it.
// The request carries NO Slim routing-results attribute, proving the middleware does
// not depend on RouteContext (routing middleware runs later in the real stack).
$credHandler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new ResponseFactory())->createResponse(303)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Origin', 'https://evil.example.com');
    }
};
$get = $reqFactory->createServerRequest('GET', 'https://gc.local/test/.admin/')
    ->withHeader('Origin', 'https://evil.example.com');

$resp = null;
try {
    $resp = $mw->process($get, $credHandler);
    check('actual request: processes without RouteContext (no RuntimeException)', true);
} catch (\Throwable $e) {
    check('actual request: processes without RouteContext — ' . $e->getMessage(), false);
}

if ($resp instanceof ResponseInterface) {
    check('actual request: Allow-Origin forced to * (handler reflection overwritten)',
        $resp->getHeaderLine('Access-Control-Allow-Origin') === '*');
    check('actual request: handler Allow-Credentials:true is STRIPPED',
        !$resp->hasHeader('Access-Control-Allow-Credentials'));
    check('actual request: downstream status preserved (303)',
        $resp->getStatusCode() === 303);
}

echo $fail ? "\n$fail FAILURE(S)\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
