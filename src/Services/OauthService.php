<?php

namespace ApiGoat\Services;

use ApiGoat\Api\ApiResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Legacy Opauth sign-in endpoint (`oauth/{p}[/{c}]`, `api/v1/oauth/{p}[/{c}]`)
 * — DISABLED.
 *
 * SECURITY (review 2026-09-23): the callback trusted an unsigned, client-
 * supplied base64 JSON `opauth` payload and logged the caller in (session or
 * JWT) as whichever authy row matched its provider + uid, so anyone could sign
 * in as any linked account; it also echoed `error_description` unescaped into
 * the page. Google sign-in is the GIS ID-token flow (verified server-side) and
 * the OAuth 2.1 server lives in OAuthAuthorize/Token/RegisterService, so this
 * class only refuses now.
 *
 * It is kept (not deleted) because emitted routes.php still instantiate it:
 *   - HTML route writes `getResponse()['error']` to the body,
 *   - API route returns `getApiResponse()` as the response.
 * Neither method reads the request payload, touches the DB or the session.
 */
class OauthService extends Service
{
    public const DISABLED_MESSAGE = 'This sign-in method is no longer available.';

    public function __construct(Request $request, Response $response, $args)
    {
        // Deliberately not parent::__construct(): no layout/menu/DB work is
        // needed to refuse.
        $this->request = $request;
        $this->response = $response;
        $this->args = is_array($args) ? $args : [];
    }

    /**
     * HTML route: the caller writes 'error' to the body when it is set.
     * @return array{error: string}
     */
    public function getResponse()
    {
        return ['error' => htmlspecialchars(self::DISABLED_MESSAGE, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    /**
     * API route: a JSON 404 envelope.
     * @return Response
     */
    public function getApiResponse()
    {
        $ApiResponse = new ApiResponse($this->args, $this->response, [
            'status' => 'failure',
            'data' => null,
            'errors' => [self::DISABLED_MESSAGE],
        ]);
        $ApiResponse->setStatus(404);
        return $ApiResponse->getResponse();
    }
}
