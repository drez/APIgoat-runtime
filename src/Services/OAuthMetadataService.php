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

    /**
     * The issuer clients discover us as — NOT necessarily where our endpoints
     * live.
     *
     * An app mounted in a dot sub-directory (the canonical GoatCheese layout:
     * the front-end owns the domain root, the admin lives under /.admin/) can
     * never serve `/.admin/.well-known/...`. Apache resolves the hidden
     * directory during the authz walk and denies it (authz_core AH01630) long
     * before mod_rewrite's per-directory fixup could rewrite the URL away — so
     * no .htaccess rule inside .admin/ can rescue it. The project-root
     * .htaccess therefore routes the ORIGIN-level `/.well-known/oauth-*` URLs
     * into this app (with_mcp::patchHtaccess + the origin route aliases in
     * Routes.php), and that is the only place a client can fetch them from.
     *
     * Hence: sub-directory install => the issuer is the bare origin, while the
     * authorize/token/register endpoints keep their real sub-directory paths
     * (RFC 8414 lets an AS place its endpoints anywhere). Root install =>
     * unchanged, the issuer stays _SITE_URL.
     */
    public static function servesOriginDiscovery(): bool
    {
        $sub = defined('_SUB_DIR_URL') ? _SUB_DIR_URL : '/';
        $segments = array_values(array_filter(explode('/', $sub), 'strlen'));
        // Exactly one segment, and it is the hidden admin directory: the project
        // root is the vhost DocumentRoot, so its .htaccess owns the origin and
        // carries the /.well-known/oauth-* rewrite into this app. Anything else
        // (root install, or a dev checkout served at /<project>/.admin/) keeps
        // serving discovery from its own prefix.
        return count($segments) === 1 && str_starts_with($segments[0], '.');
    }

    public static function issuer(): string
    {
        $site = defined('_SITE_URL') ? _SITE_URL : '';
        if (!self::servesOriginDiscovery()) {
            return $site;
        }
        $p = parse_url($site);
        if (empty($p['scheme']) || empty($p['host'])) {
            return $site;
        }
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * $base defaults to $issuer (root installs); pass _SITE_URL explicitly when
     * the endpoints live under a sub-directory the issuer no longer carries.
     */
    public static function authorizationServerMetadata(string $issuer, ?string $base = null): array
    {
        $base = rtrim($base ?? $issuer, '/') . '/';
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

    public static function protectedResourceMetadata(string $issuer, ?string $base = null): array
    {
        $base = rtrim($base ?? $issuer, '/') . '/';
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
        $issuer = self::issuer();
        $base   = defined('_SITE_URL') ? _SITE_URL : $issuer;
        $doc = ($this->args['meta'] ?? 'as') === 'pr'
            ? self::protectedResourceMetadata($issuer, $base)
            : self::authorizationServerMetadata($issuer, $base);

        $this->response->getBody()->write(json_encode($doc, JSON_UNESCAPED_SLASHES));
        return $this->response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}
