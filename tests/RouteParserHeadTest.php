<?php
// Run: php tests/RouteParserHeadTest.php   (from the runtime repo root)
//
// REGRESSION GUARD (2026-09-27): every HEAD request to an app route was a 500
// ("Method not implemented in RouteParser:HEAD") — uptime monitors, link
// checkers and crawlers use HEAD. HEAD is GET without a body (RFC 9110 9.3.2):
// Slim's router already sends it to the GET routes, and the server drops the
// body, so RouteParser parses it exactly as GET — same args, same action, same
// downstream checks (RBAC, mutating-GET refusal).

require __DIR__ . '/autoload.php';
require_once __DIR__ . '/../src/Middlewares/RouteParser.php';

use ApiGoat\Middlewares\RouteParser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

if (!defined('_SUB_DIR_URL')) {
    define('_SUB_DIR_URL', '/');
}

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

/** parsed_args RouteParser hands downstream for $method $uri, or the exception message. */
function parsed(string $method, string $uri)
{
    $seen = null;
    $handler = new class ($seen) implements RequestHandlerInterface {
        public function __construct(public &$seen) {}
        public function handle(ServerRequestInterface $r): ResponseInterface
        {
            $this->seen = $r->getAttribute('parsed_args');
            return (new ResponseFactory())->createResponse(200);
        }
    };
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    $req = (new ServerRequestFactory())->createServerRequest($method, 'https://app.test' . $uri)->withQueryParams($q);
    try {
        (new RouteParser())->process($req, $handler);
    } catch (\Throwable $e) {
        return 'EXCEPTION: ' . $e->getMessage();
    }
    return $handler->seen;
}

foreach (['/Authy/login', '/Client/edit/5', '/Client?s=abc', '/api/v1/Client/7', '/'] as $uri) {
    $get = parsed('GET', $uri);
    $head = parsed('HEAD', $uri);
    check("HEAD {$uri} parses (no exception)", is_array($head), true);
    check("HEAD {$uri} parses exactly as GET", $head, $get);
}
check('HEAD is judged as GET downstream', parsed('HEAD', '/Client/edit/5')['method'] ?? null, 'GET');
check('an unimplemented verb is still refused', str_starts_with((string) (is_string($x = parsed('TRACE', '/Client')) ? $x : ''), 'EXCEPTION: Method not implemented'), true);

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
