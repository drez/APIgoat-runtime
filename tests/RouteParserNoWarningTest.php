<?php
// Run: php tests/RouteParserNoWarningTest.php   (from the runtime repo root)
//
// apigoatacc monthly report 2026-10: RouteParser emitted `Undefined array key
// "Content-Type"` (getContentType on a request without that header) and
// `Undefined array key "query"` (the QueryBuilder sniff on a GET whose query
// string has no `query` param) into php-error.log on ordinary requests. Both
// reads must be guarded; the parse result must not change.

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
    if ($got === $want) { echo "  ok  {$label}\n"; return; }
    $fail++;
    echo "  FAIL {$label}\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n";
}

set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str . " @ " . basename($file) . ":" . $line, 0, $no, $file, $line);
});

/** parsed_args for $method $uri (optionally with a Content-Type header), or the warning/exception text. */
function parsed(string $method, string $uri, ?string $contentType = null, array $body = [])
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
    if ($contentType !== null) { $req = $req->withHeader('Content-Type', $contentType); }
    if ($body !== []) { $req = $req->withParsedBody($body); }
    try {
        (new RouteParser())->process($req, $handler);
    } catch (\Throwable $e) {
        return 'EXCEPTION: ' . $e->getMessage();
    }
    return $handler->seen;
}

foreach (['/Client?s=abc', '/Client', '/?s=1', '/Client/5?s=1', '/api/v1/Client?s=1'] as $uri) {
    $r = parsed('GET', $uri);
    check("GET {$uri} without `query` param: no warning", is_array($r) ? true : $r, true);
}
check('GET /Client?query=1: action is list', parsed('GET', '/Client?query=1')['action'] ?? null, 'list');

foreach (['/Client', '/Client/edit', '/api/v1/Client'] as $uri) {
    $r = parsed('POST', $uri);               // no parsed body, no Content-Type (bots, probes)
    check("POST {$uri} without Content-Type or body: no warning", is_array($r) ? true : $r, true);
}
$r = parsed('POST', '/Client/edit', 'application/json', ['Name' => 'x']);
check('POST with Content-Type + body still parses', $r['action'] ?? $r, 'create');

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
