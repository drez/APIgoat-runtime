<?php
namespace ApiGoat\Services;

use ApiGoat\Services\Service;
use Psr\Http\Message\ServerRequestInterface as Request;

class OAuthMetadataService extends Service
{
    private const SCOPES = ['crm:read', 'crm:write', 'offline_access'];

    /**
     * Bypass the parent BuilderLayout/BuilderMenus initialization — OAuth
     * metadata endpoints return raw JSON responses and never use the HTML rendering layer.
     */
    public function __construct(Request $request, \Psr\Http\Message\ResponseInterface $response, array $args)
    {
        $this->request  = $request;
        $this->response = $response;
        $this->args     = $args;
    }

    public static function authorizationServerMetadata(string $issuer): array
    {
        $base = rtrim($issuer, '/') . '/';
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $base . 'oauth/authorize',
            'token_endpoint' => $base . 'oauth/token',
            'registration_endpoint' => $base . 'oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::SCOPES,
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic'],
        ];
    }

    public static function protectedResourceMetadata(string $issuer): array
    {
        $base = rtrim($issuer, '/') . '/';
        return [
            'resource' => $base . 'api/v1/mcp',
            'authorization_servers' => [$issuer],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
        ];
    }

    /** $this->args['meta'] is 'as' or 'pr' (set by the route closure). */
    public function getApiResponse()
    {
        $issuer = defined('_SITE_URL') ? _SITE_URL : '';
        $doc = ($this->args['meta'] ?? 'as') === 'pr'
            ? self::protectedResourceMetadata($issuer)
            : self::authorizationServerMetadata($issuer);

        $this->response->getBody()->write(json_encode($doc, JSON_UNESCAPED_SLASHES));
        return $this->response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}
