<?php
namespace ApiGoat\Mcp;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class McpEndpoint
{
    private Request $request;
    private Response $response;
    private array $args;

    public function __construct($request, $response, array $args)
    {
        $this->request = $request;
        $this->response = $response;
        $this->args = $args;
    }

    public function handle(): Response
    {
        // 1. OAuth bearer validation (SP3 resource server)
        $factory = \ApiGoat\OAuth\OAuthServerFactory::forProject();
        if ($factory === null) {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'OAuth not configured']], 503);
        }
        try {
            $validated = $factory->resourceServer()->validateAuthenticatedRequest($this->request);
        } catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
            return $this->unauthorized();
        }
        $authyId = (int) $validated->getAttribute('oauth_user_id');
        if ($authyId <= 0) {
            return $this->unauthorized();
        }

        // 2. Hydrate the CRM session exactly like JwtBeforeHandler
        if ($_SESSION[_AUTH_VAR]->get('connected') !== 'YES') {
            $authy = \App\AuthyQuery::create()->findPk($authyId);
            if (!$authy) {
                return $this->unauthorized();
            }
            try {
                // Bypass the AuthyService constructor (BuilderLayout / web-only helpers)
                // exactly as OAuthAuthorizeService does — setSession needs none of
                // the constructor-wired state.
                $svc = (new \ReflectionClass(\App\AuthyService::class))->newInstanceWithoutConstructor();
                $svc->setSession($authy);
            } catch (\Throwable $e) {
                error_log('[mcp] setSession failed: ' . $e->getMessage());
            }
            if ($_SESSION[_AUTH_VAR]->get('connected') !== 'YES') {
                return $this->unauthorized();
            }
        }

        // 3. Parse JSON-RPC, dispatch. Stash request/response for in-process service dispatch.
        $GLOBALS['__mcp_request'] = $this->request;
        $GLOBALS['__mcp_response'] = $this->response;
        try {
            $message = json_decode((string) $this->request->getBody(), true);
            if (!is_array($message)) {
                return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 200);
            }
            $server = new McpServer(new ToolRegistry());
            $result = $server->handle($message, $_SESSION[_AUTH_VAR]);
        } finally {
            unset($GLOBALS['__mcp_request'], $GLOBALS['__mcp_response']);
        }

        // notification → 202 no body
        if ($result === null) {
            return $this->response->withStatus(202);
        }
        return $this->json($result, 200);
    }

    private function unauthorized(): Response
    {
        return $this->response
            ->withHeader('WWW-Authenticate', 'Bearer resource_metadata="' . (defined('_SITE_URL') ? _SITE_URL : '') . '.well-known/oauth-protected-resource"')
            ->withStatus(401);
    }

    private function json(array $payload, int $status): Response
    {
        $this->response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $this->response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
