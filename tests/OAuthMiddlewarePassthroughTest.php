<?php
// Run: php vendor/apigoat/runtime/tests/OAuthMiddlewarePassthroughTest.php

// Stub PSR-15 interfaces so the middleware can be loaded without a full composer env.
namespace Psr\Http\Message { interface ResponseInterface {} interface ServerRequestInterface { public function getHeaderLine(string $name): string; public function getAttribute(string $name, $default = null); } }
namespace Psr\Http\Server { interface RequestHandlerInterface {} interface MiddlewareInterface { public function process(\Psr\Http\Message\ServerRequestInterface $r, \Psr\Http\Server\RequestHandlerInterface $h): \Psr\Http\Message\ResponseInterface; } }
namespace ApiGoat\OAuth { class BearerSessionAuthenticator { const AUTHENTICATED='authenticated'; const NOT_OAUTH='not_oauth'; const UNKNOWN_USER='unknown_user'; const INACTIVE='inactive'; public static function authenticate($r): string { return self::NOT_OAUTH; } } }
namespace Slim\Psr7 { class Response implements \Psr\Http\Message\ResponseInterface {} }
namespace {

require __DIR__ . '/../src/Middlewares/OAuthResourceMiddleware.php';
use ApiGoat\Middlewares\OAuthResourceMiddleware;

function assertTrue($c, string $m): void { if (!$c) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } }

// shouldAttempt(isApi, alreadyConnected, hasBearer, isFileAction=false)
assertTrue(OAuthResourceMiddleware::shouldAttempt(true,  false, true)  === true,  'api + not-connected + bearer => attempt');
assertTrue(OAuthResourceMiddleware::shouldAttempt(false, false, true)  === false, 'non-api (not file action) => skip');
assertTrue(OAuthResourceMiddleware::shouldAttempt(true,  true,  true)  === false, 'already connected => skip');
assertTrue(OAuthResourceMiddleware::shouldAttempt(true,  false, false) === false, 'no bearer => skip');
// File actions (upload/open/file) are served by the legacy is_api=false route — hydrate there too.
assertTrue(OAuthResourceMiddleware::shouldAttempt(false, false, true,  true)  === true,  'non-api + file action + bearer => attempt');
assertTrue(OAuthResourceMiddleware::shouldAttempt(false, false, false, true)  === false, 'file action but no bearer => skip');
assertTrue(OAuthResourceMiddleware::shouldAttempt(false, true,  true,  true)  === false, 'file action but already connected => skip');

echo "PASS: OAuthResourceMiddleware::shouldAttempt OK\n"; exit(0);

} // namespace {
