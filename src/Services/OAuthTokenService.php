<?php
namespace ApiGoat\Services;

use ApiGoat\OAuth\OAuthServerFactory;
use ApiGoat\Services\Service;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * OAuth 2.1 Token endpoint controller.
 *
 * POST /oauth/token — delegates to the league authorization server's
 * respondToAccessTokenRequest(); handles authorization_code and refresh_token
 * grants. All league errors are mapped to JSON responses via
 * OAuthServerException::generateHttpResponse().
 */
class OAuthTokenService extends Service
{
    /**
     * Bypass the parent BuilderLayout/BuilderMenus initialization — OAuth
     * endpoints return raw PSR-7 responses and never use the HTML rendering layer.
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
        try {
            return $factory->authorizationServer()
                ->respondToAccessTokenRequest($this->request, $this->response);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse($this->response);
        }
    }
}
