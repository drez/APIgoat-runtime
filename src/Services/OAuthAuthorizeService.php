<?php
namespace ApiGoat\Services;

use ApiGoat\OAuth\OAuthServerFactory;
use ApiGoat\OAuth\Entities\UserEntity;
use ApiGoat\Services\Service;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * OAuth 2.1 Authorization endpoint controller.
 *
 * GET /oauth/authorize — validates the authorization request, enforces S256 PKCE,
 * reuses the existing CRM session (no new credential UI), auto-approves once the
 * user is connected, and completes the league authorization flow (302 + code).
 */
class OAuthAuthorizeService extends Service
{
    /**
     * Bypass the parent BuilderLayout/BuilderMenus initialization — OAuth
     * endpoints return raw PSR-7 responses and never use the HTML rendering
     * layer. We only need $request, $response, and $args.
     */
    public function __construct(Request $request, \Psr\Http\Message\ResponseInterface $response, array $args)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->args     = $args;
    }

    public function getApiResponse(): ResponseInterface
    {
        $factory = OAuthServerFactory::forProject();
        if ($factory === null) {
            return $this->response->withStatus(404);
        }
        $server = $factory->authorizationServer();

        try {
            $authRequest = $server->validateAuthorizationRequest($this->request);

            // S256-only guard: reject plain PKCE or a missing method.
            // League 8.x accepts both S256 and plain by default; we close
            // the plain-PKCE downgrade window here per the MCP spec.
            $method = $this->request->getQueryParams()['code_challenge_method'] ?? null;
            if ($method !== 'S256') {
                throw OAuthServerException::invalidRequest(
                    'code_challenge_method',
                    'Only S256 PKCE is supported'
                );
            }

            // Reuse the CRM browser session — no new credential UI.
            // AuthySession::get('connected') returns $this->isConnected;
            // AuthySession::get('id') returns $this->authyId.
            $session = $_SESSION[_AUTH_VAR] ?? null;
            if (!$session || $session->get('connected') !== 'YES') {
                // Stash the pending authorization request so that the browser
                // can resume it after the CRM login succeeds.
                $_SESSION['oauth_pending'] = serialize($authRequest);
                return $this->renderCrmLogin();
            }

            $authyId = (string) $session->get('id');
            $authRequest->setUser(new UserEntity($authyId));
            $authRequest->setAuthorizationApproved(true); // v1: auto-grant after CRM login

            return $server->completeAuthorizationRequest($authRequest, $this->response);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse($this->response);
        }
    }

    /**
     * 302-redirect to the existing CRM login page with a return_to param so
     * the browser comes back to oauth/authorize after a successful CRM login.
     *
     * CRM login reuse approach: 302-redirect to the project's /login route
     * with return_to=<current authorize URL + query string>. The existing CRM
     * login form (with its CSRF protection) handles authentication. After a
     * successful login the user is redirected back to oauth/authorize which
     * now has a connected session and auto-approves. No new credential UI is
     * introduced; AuthyMiddleware CSRF remains the auth gate.
     *
     * Note: if the CRM login does not honour return_to, the stashed
     * $_SESSION['oauth_pending'] can be used for in-session resume instead.
     */
    private function renderCrmLogin(): ResponseInterface
    {
        $loginUrl = (defined('_SUB_DIR_URL') ? _SUB_DIR_URL : '/') . 'login';
        $return = '/' . ltrim($this->request->getUri()->getPath(), '/');
        if ($this->request->getUri()->getQuery()) {
            $return .= '?' . $this->request->getUri()->getQuery();
        }
        return $this->response
            ->withHeader('Location', $loginUrl . '?return_to=' . rawurlencode($return))
            ->withStatus(302);
    }
}
