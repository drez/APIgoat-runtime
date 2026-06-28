<?php
namespace ApiGoat\Services;

use ApiGoat\OAuth\OAuthServerFactory;
use ApiGoat\OAuth\Entities\UserEntity;
use ApiGoat\Services\Service;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * OAuth 2.1 Authorization endpoint controller — self-contained login + consent.
 *
 * GET  /oauth/authorize
 *   - validateAuthorizationRequest (league parses client/redirect/scopes/PKCE)
 *   - S256 PKCE guard (rejects plain / missing method)
 *   - if the CRM session is NOT connected → render the login page (no code)
 *   - if connected → render the consent page (no auto-grant, no code)
 *
 * POST /oauth/authorize  (the only path that can issue a code)
 *   - re-validateAuthorizationRequest from the carried-over original params
 *   - re-apply the S256 guard
 *   - not connected + credentials → CSRF-verified CRM login (reuses the exact
 *     tryLog()/setSession() path the human login uses) → on success render consent
 *   - connected + consent decision → CSRF-verified Allow → completeAuthorizationRequest
 *     (302 + code); Deny → access_denied redirect, no code
 *
 * Security invariants:
 *   - setAuthorizationApproved(true) is reachable ONLY from a POST that passed a
 *     hash_equals CSRF check on a connected session — never from a GET.
 *   - A GET never issues a code.
 *   - The S256 guard runs on both GET and POST before any approval.
 *   - A missing/mismatched CSRF token never approves.
 *   - Consent is shown on every authorize (no per-client "remember" in v1).
 */
class OAuthAuthorizeService extends Service
{
    /** Original authorize params carried through the login/consent POST round-trips. */
    private const OAUTH_PARAMS = [
        'response_type', 'client_id', 'redirect_uri',
        'scope', 'state', 'code_challenge', 'code_challenge_method',
    ];

    /**
     * Bypass the parent BuilderLayout/BuilderMenus initialization — OAuth
     * endpoints return raw PSR-7 responses and never use the HTML rendering
     * layer (and BuilderLayout pulls in web-only helpers unavailable in CLI/test).
     * We only need $request, $response, and $args.
     */
    public function __construct(Request $request, ResponseInterface $response, array $args)
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

        $isPost = strtoupper($this->request->getMethod()) === 'POST';
        $body   = $isPost ? (array) ($this->request->getParsedBody() ?? []) : [];

        // Original authorize params: query string on GET, hidden form fields on POST.
        $params = [];
        $source = $isPost ? $body : $this->request->getQueryParams();
        foreach (self::OAUTH_PARAMS as $k) {
            if (isset($source[$k]) && $source[$k] !== '') {
                $params[$k] = (string) $source[$k];
            }
        }

        try {
            // league reads the authorization request from the query params, so we
            // rebuild a request carrying the original params. This makes the POST
            // round-trip validate identically to the GET — the hidden fields are
            // promoted back into the query the grant inspects.
            $validationRequest = $this->request->withQueryParams($params);
            $authRequest = $server->validateAuthorizationRequest($validationRequest);

            // S256-only guard — runs on BOTH GET and POST, before any approval,
            // closing the plain-PKCE downgrade window per the MCP spec.
            if (($params['code_challenge_method'] ?? null) !== 'S256') {
                throw OAuthServerException::invalidRequest(
                    'code_challenge_method',
                    'Only S256 PKCE is supported'
                );
            }

            $session   = $_SESSION[_AUTH_VAR] ?? null;
            $connected = ($session && $session->get('connected') === 'YES');

            if ($isPost) {
                $submittedCsrf = (string) ($body['csrf'] ?? '');

                // --- Not connected: treat the POST as a login submission. ---
                if (!$connected) {
                    $u = (string) ($body['u'] ?? '');
                    $p = (string) ($body['p'] ?? '');
                    if ($u === '' && $p === '') {
                        // No credentials → just (re)render the login page, no code.
                        return $this->renderLogin($authRequest, $params);
                    }
                    // CSRF gate before touching the credential path.
                    if (!$this->csrfOk($session, $submittedCsrf)) {
                        return $this->renderLogin($authRequest, $params, _('Your session expired. Please try again.'));
                    }
                    // Reuse the CRM credential path verbatim — never reimplement it.
                    $this->crmLogin($u, $p, $submittedCsrf);
                    $session   = $_SESSION[_AUTH_VAR] ?? null;
                    $connected = ($session && $session->get('connected') === 'YES');
                    if (!$connected) {
                        return $this->renderLogin($authRequest, $params, _('Sign-in failed. Check your details and try again.'));
                    }
                    // Logged in now → show consent (no decision submitted yet, no code).
                    return $this->renderConsent($authRequest, $params);
                }

                // --- Connected: treat the POST as a consent decision. ---
                $decision = (string) ($body['consent'] ?? '');
                if ($decision === '') {
                    // Connected but no decision yet (e.g. just logged in) → consent.
                    return $this->renderConsent($authRequest, $params);
                }
                // CSRF gate — a missing/mismatched token NEVER approves.
                if (!$this->csrfOk($session, $submittedCsrf)) {
                    return $this->response->withStatus(400);
                }
                if ($decision === 'allow') {
                    $authRequest->setUser(new UserEntity((string) $session->get('id')));
                    $authRequest->setAuthorizationApproved(true);
                    return $server->completeAuthorizationRequest($authRequest, $this->response);
                }
                // Deny (or any non-allow value) → access_denied redirect, no code.
                return $this->denyRedirect($authRequest, $params);
            }

            // --- GET: never issues a code; only renders login or consent. ---
            if (!$connected) {
                return $this->renderLogin($authRequest, $params);
            }
            return $this->renderConsent($authRequest, $params);
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse($this->response);
        }
    }

    /**
     * Verify a submitted CSRF token against the session token with hash_equals.
     * Fails closed: a missing session token or an empty/mismatched submission
     * is never accepted.
     */
    private function csrfOk($session, string $submitted): bool
    {
        if (!$session || !method_exists($session, 'getCsrf')) {
            return false;
        }
        $token = (string) $session->getCsrf();
        return $token !== '' && hash_equals($token, $submitted);
    }

    /**
     * Authenticate via the exact CRM login path (tryLog → queryUser +
     * password_verify → setSession). The AuthyService constructor wires the
     * HTML/BuilderLayout layer (web-only helpers), which tryLog/setSession do
     * not need — instantiate without the constructor, matching the established
     * runtime/test seam. Password verification is NOT reimplemented here.
     */
    private function crmLogin(string $u, string $p, string $csrf): void
    {
        $svc = (new \ReflectionClass(\App\AuthyService::class))->newInstanceWithoutConstructor();
        $svc->tryLog($u, $p, false, null, $csrf);
    }

    /**
     * Ensure the session carries a CSRF token (mirrors the normal login form:
     * AuthyForm seeds one when empty), so the consent/login POST can be verified.
     */
    private function ensureCsrf($session): string
    {
        if ($session && method_exists($session, 'getCsrf')) {
            $token = (string) $session->getCsrf();
            if ($token === '' && method_exists($session, 'setCsrf')) {
                $token = md5(uniqid('GoAt') . uniqid('', true));
                $session->setCsrf($token);
            }
            return $token;
        }
        return '';
    }

    /** Build the access_denied redirect back to the client (no code issued). */
    private function denyRedirect(AuthorizationRequest $authRequest, array $params): ResponseInterface
    {
        $redirect = (string) $authRequest->getRedirectUri();
        $query    = ['error' => 'access_denied'];
        $state    = $authRequest->getState();
        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }
        $sep = (strpos($redirect, '?') === false) ? '?' : '&';
        return $this->response
            ->withHeader('Location', $redirect . $sep . http_build_query($query))
            ->withStatus(302);
    }

    /** Render the self-contained login page (200 HTML, no code). */
    private function renderLogin(AuthorizationRequest $authRequest, array $params, string $error = ''): ResponseInterface
    {
        $session    = $_SESSION[_AUTH_VAR] ?? null;
        $csrf       = $this->ensureCsrf($session);
        $clientName = htmlspecialchars((string) $authRequest->getClient()->getName(), ENT_QUOTES);
        $hidden     = $this->hiddenParams($params) . $this->hiddenField('csrf', $csrf);
        $action     = htmlspecialchars($this->actionUrl(), ENT_QUOTES);
        $errHtml    = $error !== '' ? '<p style="color:#d33;margin:0 0 12px;">' . htmlspecialchars($error, ENT_QUOTES) . '</p>' : '';

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . _('Sign in') . '</title></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;margin:0;">'
            . '<div style="max-width:360px;margin:48px auto;background:#fff;padding:28px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);">'
            . '<h2 style="margin-top:0;color:#2f2f2f;">' . _('Sign in to continue') . '</h2>'
            . '<p style="color:#555;">' . sprintf(_('%s is requesting access to your CRM account.'), '<strong>' . $clientName . '</strong>') . '</p>'
            . $errHtml
            . '<form method="post" action="' . $action . '" style="display:flex;flex-direction:column;gap:12px;">'
            . $hidden
            . '<input type="text" name="u" placeholder="' . _('Username or email') . '" autocomplete="username" required style="padding:10px;border:1px solid #ccc;border-radius:6px;">'
            . '<input type="password" name="p" placeholder="' . _('Password') . '" autocomplete="current-password" required style="padding:10px;border:1px solid #ccc;border-radius:6px;">'
            . '<button type="submit" style="padding:10px;background:#00d1b2;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:15px;">' . _('Sign in') . '</button>'
            . '</form></div></body></html>';

        return $this->htmlResponse($html);
    }

    /** Render the self-contained consent page (200 HTML, Allow/Deny, no code). */
    private function renderConsent(AuthorizationRequest $authRequest, array $params): ResponseInterface
    {
        $session    = $_SESSION[_AUTH_VAR] ?? null;
        $csrf       = $this->ensureCsrf($session);
        $clientName = htmlspecialchars((string) $authRequest->getClient()->getName(), ENT_QUOTES);

        $scopeItems = '';
        foreach ($authRequest->getScopes() as $scope) {
            $scopeItems .= '<li style="padding:4px 0;">' . htmlspecialchars((string) $scope->getIdentifier(), ENT_QUOTES) . '</li>';
        }
        if ($scopeItems === '') {
            $scopeItems = '<li style="padding:4px 0;">' . _('Basic access') . '</li>';
        }

        $hidden = $this->hiddenParams($params) . $this->hiddenField('csrf', $csrf);
        $action = htmlspecialchars($this->actionUrl(), ENT_QUOTES);

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . _('Authorize access') . '</title></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;margin:0;">'
            . '<div style="max-width:420px;margin:48px auto;background:#fff;padding:28px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);">'
            . '<h2 style="margin-top:0;color:#2f2f2f;">' . sprintf(_('Authorize %s'), '<strong>' . $clientName . '</strong>') . '</h2>'
            . '<p style="color:#555;">' . sprintf(_('"%s" is requesting access to your CRM account.'), $clientName) . '</p>'
            . '<p style="color:#555;margin-bottom:4px;">' . _('It will be able to:') . '</p>'
            . '<ul style="color:#333;margin-top:0;">' . $scopeItems . '</ul>'
            . '<form method="post" action="' . $action . '" style="display:flex;gap:12px;margin-top:18px;">'
            . $hidden
            . '<button type="submit" name="consent" value="deny" style="flex:1;padding:10px;background:#eee;color:#333;border:0;border-radius:6px;cursor:pointer;font-size:15px;">' . _('Deny') . '</button>'
            . '<button type="submit" name="consent" value="allow" style="flex:1;padding:10px;background:#00d1b2;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:15px;">' . _('Allow') . '</button>'
            . '</form></div></body></html>';

        return $this->htmlResponse($html);
    }

    /** Hidden inputs for every carried-over authorize param (escaped). */
    private function hiddenParams(array $params): string
    {
        $out = '';
        foreach ($params as $k => $v) {
            $out .= $this->hiddenField((string) $k, (string) $v);
        }
        return $out;
    }

    private function hiddenField(string $name, string $value): string
    {
        return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES)
            . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
    }

    /** Same-endpoint POST target for the login/consent forms. */
    private function actionUrl(): string
    {
        return '/' . ltrim($this->request->getUri()->getPath(), '/');
    }

    private function htmlResponse(string $html): ResponseInterface
    {
        $resp = $this->response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus(200);
        $resp->getBody()->write($html);
        return $resp;
    }
}
