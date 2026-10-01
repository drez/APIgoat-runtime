<?php

namespace ApiGoat\Auth;

use ApiGoat\Ops\SecEvent;
use ApiGoat\Sessions\AuthySession;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The two WebHandoff endpoints (see WebHandoff). A project registers them:
 *
 *   api/v1/WebHandoff/mint  POST, bearer   -> WebHandoffRoutes::mint
 *   WebHandoff/open         GET,  public   -> WebHandoffRoutes::open
 *
 * plus `self_service_models: WebHandoff => [mint]` (signed-in, no model
 * RBAC) and `WebHandoff/open` in privileges exclude (anonymous by design:
 * the code IS the credential).
 */
final class WebHandoffRoutes
{
    public static function mint(Request $request, Response $response): Response
    {
        if ($request->getMethod() !== 'POST') {
            return self::json($response->withHeader('Allow', 'POST'), 405, ['status' => 'error', 'message' => 'POST only']);
        }
        $s = $_SESSION[_AUTH_VAR] ?? null;
        $id = ($s instanceof AuthySession && $s->get('connected') === 'YES') ? (int) $s->get('id') : 0;
        if ($id <= 0) {
            return self::json($response, 401, ['status' => 'error', 'message' => 'Not signed in']);
        }
        $body = $request->getParsedBody();
        $path = \is_array($body) ? (string) ($body['path'] ?? '') : '';
        if (!WebHandoff::validPath($path)) {
            return self::json($response, 400, ['status' => 'error', 'message' => 'Invalid path']);
        }
        $code = WebHandoff::mint($id, $path);
        if ($code === null) {
            return self::json($response, 503, ['status' => 'error', 'message' => 'Handoff unavailable on this server']);
        }
        return self::json($response, 200, ['status' => 'success', 'url' => _SITE_URL . 'WebHandoff/open?c=' . $code, 'ttl' => WebHandoff::TTL]);
    }

    public static function open(Request $request, Response $response): Response
    {
        $login = $response->withStatus(303)->withHeader('Location', _SITE_URL . 'Authy/login')->withHeader('Cache-Control', 'no-store');
        // A bearer request never owns a cookie session — the handoff must be a plain page load.
        // (Value, not presence: Apache's rewrite passes an EMPTY Authorization header on every request.)
        if ($request->getMethod() !== 'GET' || trim($request->getHeaderLine('Authorization')) !== '') {
            return $login;
        }
        $claim = WebHandoff::consume((string) ($request->getQueryParams()['c'] ?? ''));
        if ($claim === null) {
            SecEvent::record('web_handoff_reject', null, null, 'unknown, used or expired code');
            return $login;
        }
        $authy = \App\AuthyQuery::create()->findPk($claim['id_authy']);
        if ($authy === null || AuthySession::authyLockedOut($authy)) {
            SecEvent::record('web_handoff_reject', $claim['id_authy'], null, 'inactive user');
            return $login;
        }
        $ok = (new \ReflectionClass(\App\AuthyService::class))->newInstanceWithoutConstructor()->setSession($authy);
        if ($ok !== true) {
            SecEvent::record('web_handoff_reject', $claim['id_authy'], null, 'session refused');
            return $login;
        }
        SecEvent::record('web_handoff', $claim['id_authy'], null, $claim['path']);
        return $response->withStatus(303)
            ->withHeader('Location', _SITE_URL . $claim['path'])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private static function json(Response $response, int $status, array $data): Response
    {
        $response->getBody()->write((string) \json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
