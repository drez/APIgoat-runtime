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
        // 1+2. OAuth bearer validation + session hydration (shared helper).
        $status = \ApiGoat\OAuth\BearerSessionAuthenticator::authenticate($this->request);
        if ($status === \ApiGoat\OAuth\BearerSessionAuthenticator::NOT_OAUTH) {
            // Preserve prior behavior: MCP requires OAuth; distinguish not-configured vs bad token.
            if (\ApiGoat\OAuth\OAuthServerFactory::forProject() === null) {
                return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'OAuth not configured']], 503);
            }
            return $this->unauthorized();
        }
        if ($status !== \ApiGoat\OAuth\BearerSessionAuthenticator::AUTHENTICATED) {
            return $this->unauthorized();
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
