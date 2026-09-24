<?php
// Two gaps in AuthyMiddleware (review 2026-09-24):
//  - checkPrivileges() judged RouteParser's parsed action. A GUI route whose
//    path pins no action keeps the client's `a` (RouteHelper: query for GET,
//    parsed body otherwise) and Service::getResponse() dispatches on it, so
//    POST /Invoice a=approveInvoice passed with only 'r' (judged as list/create).
//  - rbac_public=='passed' skipped the login gate on any route; api_rbac only
//    judges /api/v* routes, and a '/api/v' substring once leaked the pass to
//    GUI paths like /Product/list//api/v1.
namespace {
    // InvalidSessionRenderer's GUI branch uses the project's html helpers.
    if (!function_exists('div')) {
        function div($c = '', $id = '', $attr = '') { return '<div>' . $c . '</div>'; }
    }
}

namespace ApiGoat\Tests\Security {

use ApiGoat\Handlers\InvalidSessionRenderer;
use ApiGoat\Middlewares\AuthyMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'ApiGoat\\')) {
        $f = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
}, true, true);

if (!\defined('_AUTH_VAR')) {
    \define('_AUTH_VAR', 'aea_auth');
}
if (!\defined('_SUB_DIR_URL')) {
    \define('_SUB_DIR_URL', '/');
}

/** Connected non-admin holding only 'r' on every model. */
final class AeaReadOnlySession
{
    public $aclGroup;
    public function __construct(private string $connected = 'YES') {}
    public function isAdmin() { return false; }
    public function hasRights($m = '', $r = '') { return $r === 'r'; }
    public function get($k)
    {
        if ($k === 'connected') { return $this->connected; }
        if ($k === 'isRoot') { return false; }
        return null;
    }
}

final class AeaHandler implements RequestHandlerInterface
{
    public bool $called = false;
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;
        return new Response(200);
    }
}

final class AuthyEffectiveActionTest extends TestCase
{
    private const MAP = [
        'action'  => ['list' => 'r', 'view' => 'r', 'create' => 'a', 'update' => 'w', 'delete' => 'd'],
        'exclude' => ['Authy/login', 'GuiManager'],
    ];

    protected function tearDown(): void
    {
        unset($_SESSION[\_AUTH_VAR]);
    }

    private function middleware(array $args): array
    {
        $ref = new \ReflectionClass(AuthyMiddleware::class);
        $mw = $ref->newInstanceWithoutConstructor();
        foreach (['privilegeMap' => self::MAP, 'args' => $args + ['is_api' => false]] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($mw, $value);
        }
        return [$ref, $mw];
    }

    /** @return bool|InvalidSessionRenderer */
    private function privileges(string $method, string $uri, array $args, ?array $body = null)
    {
        $_SESSION[\_AUTH_VAR] = new AeaReadOnlySession();
        [$ref, $mw] = $this->middleware($args);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://app.test' . $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $m = $ref->getMethod('checkPrivileges');
        $m->setAccessible(true);
        return @$m->invoke($mw, $request);
    }

    public function test_body_action_on_an_unpinned_gui_route_is_judged(): void
    {
        // RouteParser reads POST /Invoice as 'create'/'list'; the service runs a=approveInvoice.
        $r = $this->privileges('POST', '/Invoice', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list'], ['a' => 'approveInvoice']);
        $this->assertInstanceOf(InvalidSessionRenderer::class, $r, 'custom body action needs w');
        $r = $this->privileges('POST', '/Invoice', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list'], ['a' => 'delete']);
        $this->assertInstanceOf(InvalidSessionRenderer::class, $r, 'mapped body action needs its mapped right');
        $r = $this->privileges('GET', '/Invoice?a=update', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list']);
        $this->assertInstanceOf(InvalidSessionRenderer::class, $r, 'GET query a is the dispatched action');
    }

    public function test_reads_and_pinned_paths_keep_working(): void
    {
        $this->assertFalse($this->privileges('POST', '/Invoice', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list'], ['a' => 'list']));
        $this->assertFalse($this->privileges('GET', '/Invoice?a=view', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list']));
        $this->assertFalse($this->privileges('POST', '/Invoice', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list'], ['a' => 'chat']), 'read-only POST action');
        // Path-pinned {a}: RouteHelper reasserts it, the body a is ignored.
        $this->assertFalse($this->privileges('POST', '/Invoice/list', ['route' => 'Invoice/list', 'model' => 'Invoice', 'action' => 'list'], ['a' => 'delete']));
        // No body a: unchanged.
        $this->assertFalse($this->privileges('POST', '/Invoice', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list'], ['ms' => '1']));
        // JSON API does not dispatch on a.
        $this->assertFalse($this->privileges('POST', '/api/v1/Invoice', ['route' => 'Invoice', 'model' => 'Invoice', 'action' => 'list', 'is_api' => true], ['a' => 'delete']));
        // Exemptions still apply.
        $this->assertFalse($this->privileges('POST', '/GuiManager', ['route' => 'GuiManager', 'model' => 'GuiManager', 'action' => 'list'], ['a' => 'alive']));
        $this->assertFalse($this->privileges('POST', '/Account', ['route' => 'Account', 'model' => 'Account', 'action' => 'list'], ['a' => 'save']));
    }

    public function test_rbac_public_pass_is_ignored_on_a_gui_route(): void
    {
        $_SESSION[\_AUTH_VAR] = new \ApiGoat\Sessions\AuthySession();
        [$ref, $mw] = $this->middleware(['route' => 'Product/list/api/v1', 'model' => 'Product', 'action' => 'list']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://app.test/Product/list//api/v1')
            ->withAttribute('parsed_args', ['route' => 'Product/list/api/v1', 'model' => 'Product', 'action' => 'list', 'is_api' => false])
            ->withAttribute('rbac_public', 'passed');
        $handler = new AeaHandler();
        $response = @$mw->process($request, $handler);
        $this->assertFalse($handler->called, 'GUI route must not ride the API public pass');
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/Authy/login', $response->getHeaderLine('Location'));
    }

    public function test_rbac_public_pass_still_serves_api_routes(): void
    {
        [$ref, $mw] = $this->middleware(['route' => 'Product', 'model' => 'Product', 'action' => 'list', 'is_api' => true]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://app.test/api/v1/Product')
            ->withAttribute('parsed_args', ['route' => 'Product', 'model' => 'Product', 'action' => 'list', 'is_api' => true])
            ->withAttribute('rbac_public', 'passed');
        $handler = new AeaHandler();
        @$mw->process($request, $handler);
        $this->assertTrue($handler->called);
    }
}
}
