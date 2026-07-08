<?php
namespace ApiGoat\Middlewares;

use ApiGoat\Api\ApiResponse;
use ApiGoat\Handlers\InvalidSessionRenderer;
use Apigoat\Sessions\AuthySession;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Psr7\Response;

/**
 * Description of Authy
 *
 * @author sysadmin
 */
class AuthyMiddleware implements MiddlewareInterface
{
    use \ApiGoat\ACL\AuthyACL;
    private $privilegeMap;
    private $args;
    private $response;

    public function __construct(ResponseFactoryInterface $responseFactory = null)
    {
        $this->privilegeMap = (require _BASE_DIR . "config/privileges.map.php");
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->args = $request->getAttribute('parsed_args');

        // OAuth discovery documents (RFC 8414 / 9728) are inherently public — they
        // must be reachable without a CRM session so an MCP client can bootstrap.
        if (strpos($request->getUri()->getPath(), '/.well-known/') !== false) {
            return $handler->handle($request);
        }

        // public API route
        if ($request->getAttribute('rbac_public') == 'passed') {
            $response = $handler->handle($request);
            return $response;
        }

        $access = $this->checkPrivileges($request);

        // validate authentication if not an API call
        if (! $this->args['is_api']) {
            if (! is_object($_SESSION[_AUTH_VAR]) or (get_class($_SESSION[_AUTH_VAR]) != 'ApiGoat\Sessions\AuthySession')) {
                unset($_SESSION[_AUTH_VAR]);
                $_SESSION[_AUTH_VAR] = new AuthySession();
                $_SESSION[_AUTH_VAR]->set('isConnected', 'NO');
            }

            // Stale-session guard: a session can outlive its user (DB reseed,
            // deleted account). Such a ghost session would stamp a now-invalid
            // id on every audit-stamped write and 500 on the authy FK. If the
            // user row is definitively gone, clear the session so the redirect
            // below sends them to re-login with a valid id. A DB error (query
            // throws) must NOT log anyone out — only a successful "no such row".
            // The SELECT only matters before a write (the FK stamp) — for reads a
            // ghost session just renders a page. Run it on every state-changing
            // request, but throttle it to one check per minute for GETs so page
            // browsing doesn't pay a DB round-trip per request.
            $gcStaleRecheck = $request->getMethod() !== 'GET'
                || (time() - (int) $_SESSION[_AUTH_VAR]->get('stale_check_ts')) > 60;
            if ($gcStaleRecheck && $_SESSION[_AUTH_VAR]->get('connected') == 'YES' && $_SESSION[_AUTH_VAR]->getIdAuthy()) {
                try {
                    if (\App\AuthyQuery::create()->findPk($_SESSION[_AUTH_VAR]->getIdAuthy()) === null) {
                        unset($_SESSION[_AUTH_VAR]);
                        $_SESSION[_AUTH_VAR] = new AuthySession();
                        $_SESSION[_AUTH_VAR]->set('isConnected', 'NO');
                    } else {
                        $_SESSION[_AUTH_VAR]->set('stale_check_ts', time());
                    }
                } catch (\Exception $e) { /* DB transient: keep the session, don't lock out */ }
            }

            if ($_SESSION[_AUTH_VAR]->get('connected') != 'YES' && $access) {

                if (strtolower($this->args['model']) != "oauth" && $this->args['action'] != "oauth") {
                    if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                        $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => ['Authentication required']]);
                        $ApiResponse->setStatus(401);
                        return $ApiResponse->getResponse();
                    }
                    $response = new Response();
                    return $response
                        ->withHeader('Location', _SUB_DIR_URL . 'Authy/login')
                        ->withHeader('Cache-Control', 'no-store')
                        ->withStatus(303);
                } else {
                    $response = $handler->handle($request);
                    return $response;
                }
            }
        } elseif ($_SESSION[_AUTH_VAR]->get('connected') != 'YES' && ! $this->checkExclude($this->args['route']) && strtolower($this->args['model']) != "oauth" && $this->args['action'] != "oauth") {
            $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => ['Authentication required']]);
            $ApiResponse->setStatus(401);
            return $ApiResponse->getResponse();
        }

        $csrfFailure = $this->checkCsrf($request);
        if ($csrfFailure !== null) {
            return $csrfFailure;
        }

        $this->checkUserSwitch($request);

       // $access = $this->checkPrivileges($request);
        if (false !== $access) {
            // access denied
            if ($access instanceof InvalidSessionRenderer) {
                if ($this->args['is_api']) {
                    $ApiResponse = new ApiResponse($this->args, $this->response, (($access->getMessage()) ? $access->getMessage() : []));
                    $ApiResponse->setStatus(403);
                    return $ApiResponse->getResponse();
                } else {
                    // progress anyways
                    $response = $handler->handle($request);
                    $response = new Response();
                    $request  = $request->withAttribute('authy_access', 'denied');
                    $request  = $request->withAttribute('authy_message', $access->getMessage());
                    $response->getBody()->write($access->getMessage());
                    return $response->withStatus(403);
                }
            } else {
                throw new HttpForbiddenException($request, $access);
            }
        } else {
            $request  = $request->withAttribute('authy_access', 'full');
            $response = $handler->handle($request);
        }
        return $response;
    }

    /**
     * CSRF gate for state-changing session-authenticated requests.
     *
     * Requires a valid session token — the X-Csrf-Token header (attached by the
     * client fetch wrapper and the upload XHR) or the `csrf` body field (forms),
     * compared with hash_equals. The old X-Requested-With-only fallback was
     * removed: its safety depended on CORS never allowing credentialed cross-
     * origin requests, so a token is the robust, config-independent defense.
     * CorsMiddleware additionally guarantees no credentialed CORS. API (JWT)
     * routes are exempt — they carry no ambient cookie credential.
     *
     * @return ResponseInterface|null a 403 response, or null when allowed
     */
    private function checkCsrf(ServerRequestInterface $request): ?ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        // SECURITY (review R5): exempt only genuine Bearer-token requests (no
        // ambient cookie credential to forge), NOT every is_api route. A
        // cookie-authenticated write to an API route must still carry the CSRF
        // token — the first-party client already attaches it to all non-GET.
        $hasBearerAuth = stripos($request->getHeaderLine('Authorization'), 'Bearer ') === 0;
        if ($hasBearerAuth
            || ! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $_SESSION[_AUTH_VAR]->get('connected') != 'YES'
            || $this->checkExclude($this->args['route'])) {
            return null;
        }

        $token = $request->getHeaderLine('X-Csrf-Token');
        if ($token === '' && isset($this->args['data']['csrf'])) {
            $token = (string) $this->args['data']['csrf'];
        }
        $sessionToken = method_exists($_SESSION[_AUTH_VAR], 'getCsrf')
            ? (string) $_SESSION[_AUTH_VAR]->getCsrf() : '';

        if ($token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token)) {
            return null;
        }

        error_log('csrf rejected: ' . $method . ' ' . ($this->args['route'] ?? '')
            . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
            . ' uid=' . $_SESSION[_AUTH_VAR]->get('id'));
        $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => ['Invalid or missing CSRF token']]);
        $ApiResponse->setStatus(403);
        return $ApiResponse->getResponse();
    }

    private function checkUserSwitch($request)
    {
        if (! $_SESSION[_AUTH_VAR]->get('isRoot')) {
            return;
        }

        if (empty($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'])) {
            $_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] = bin2hex(random_bytes(16));
        }
        if (empty($_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'])) {
            $_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'] = $_SESSION[_AUTH_VAR]->get('id');
        }

        if (! isset($this->args['data']['iarc']) || ! $this->args['data']['iarc']) {
            return;
        }

        $submittedCsrf = $this->args['data']['iarc_csrf'] ?? '';
        $sessionCsrf   = (string) ($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] ?? '');
        if ($submittedCsrf === '' || $sessionCsrf === '' || ! hash_equals($sessionCsrf, (string) $submittedCsrf)) {
            error_log('iarc switch rejected: csrf mismatch from ' . $_SERVER['REMOTE_ADDR'] . ' uid=' . $_SESSION[_AUTH_VAR]->get('id'));
            return;
        }

        $authyObj = \App\AuthyQuery::create()->findPk($this->args['data']['iarc']);
        if (! $authyObj || ! $authyObj->getIdAuthy()) {
            return;
        }

        $originalRootId     = $_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'];
        $targetUsername     = $authyObj->getUsername();

        $AuthyForm = new \App\AuthyService($request, null, $this->args['data']);
        $AuthyForm->setSession($authyObj, $targetUsername);
        $_SESSION[_AUTH_VAR]->set('isRoot', true);
        $_SESSION[_AUTH_VAR]->sessVar['IdAuthy']        = $this->args['data']['iarc'];
        $_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'] = $originalRootId;

        try {
            $al = new \App\AuthyLog();
            $al->setIp($_SERVER['REMOTE_ADDR']);
            $al->setTimestamp(time());
            $al->setLogin($targetUsername);
            $al->setIdAuthy($originalRootId);
            $al->setResult('switch');
            $al->save();
        } catch (\Exception $e) {
            error_log('iarc switch audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Summary of checkPrivileges
     * @param mixed $request
     * @return bool|InvalidSessionRenderer
     * Return false if no privileges are required
     */
    private function checkPrivileges($request)
    {

        // public route
        if ($this->checkExclude($this->args['route'])) {
            return false;
        }

        if($_SESSION[_AUTH_VAR]->get('connected') != 'YES'){
            return true;
        }

        if ($_SESSION[_AUTH_VAR]->get('isRoot')) {
            return false;
        }

        // Self-service account routes (`/Account` page + `/api/v1/Account/...`):
        // any authenticated user manages their OWN account. AccountService /
        // AccountServiceWrapper only ever read/write $_SESSION[_AUTH_VAR]'s own
        // row (id from the session, never a client-supplied id), so there is no
        // row to scope and no privilege to require beyond being logged in —
        // which is already enforced above (connected != 'YES' -> return true).
        // "Account" here is the URL path segment, NOT a real RBAC model (the
        // model is BankAccount, whose display label happens to be "Account"), so
        // authorize('Account', ...) can never succeed and can never be granted
        // via the rights matrix — locking every non-admin user out of their own
        // account page. Exempt it from the model-RBAC check, mirroring the way
        // the `oauth` route is special-cased elsewhere in this middleware.
        //
        // "oauth" (GET/POST /oauth/authorize, the PKCE consent step) is the same
        // situation: the segment is not an RBAC model, so authorize('oauth', ...)
        // locked every non-Admin-group user out of the mobile app sign-in. The
        // consent only covers the user's OWN identity; the issued bearer is
        // authorized per-operation downstream (api_rbac + Api::authorize + ACL)
        // exactly like a browser session, so being authenticated is the right
        // bar here — and that is already enforced above.
        //
        // "_meta" (GET /api/v1/_meta) is the catalog endpoint the mobile app
        // boots from. It is not an RBAC model either, and MetaService already
        // filters entities/screens/menu to the calling user's rights (that
        // per-user filter IS the authorization boundary), so any authenticated
        // user may read their own filtered view of it.
        //
        // "push" (POST /api/v1/Push[/test]) registers the caller's OWN device
        // token / sends a test to the caller's OWN devices — self-service, not
        // an RBAC model.
        if (in_array(strtolower((string) $this->args['model']), ['account', 'oauth', '_meta', 'push'], true)) {
            return false;
        }

        // "ApiGoat/geocode" + "ApiGoat/reverseGeocode" (GET /ApiGoat/geocode…,
        // also mounted at /api/v1/ApiGoat/… for bearer clients) proxy read-only
        // Nominatim lookups for the location input widget. "ApiGoat" is a URL
        // namespace, not an RBAC model, so authorize('ApiGoat', 'r') can never
        // succeed for a non-root user and would lock the widget out. Being
        // authenticated is the right bar (already enforced above: connected !=
        // 'YES' -> return true). Deliberately scoped to these two actions ONLY —
        // other ApiGoat/* routes (sendEmail, reset, account) keep their gates.
        if (strtolower((string) $this->args['model']) === 'apigoat'
            && in_array(strtolower((string) $this->args['action']), ['geocode', 'reversegeocode'], true)) {
            return false;
        }

        $requiredPrivileges = $this->getRequiredPrivilege($this->args['action'], $this->args['model']);
        if ($requiredPrivileges === false) {
            // Custom (non-CRUD) action, not in the privilege map. Infer the
            // required right from the HTTP method (review R3): a mutating verb
            // needs write, so a state-changing custom action can no longer be
            // invoked with read-only rights. A read action reached via POST must
            // be granted explicitly (add it to the privilege map / api_rbac).
            $model   = $this->args['model'];
            $reqMethod = strtoupper($request->getMethod());
            $requiredPrivileges = in_array($reqMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'w' : 'r';
        } else {
            $model = $this->args['model'];
        }

        if(empty($model)){
            return false;
        }

        if (! empty($requiredPrivileges)) {
            if (! $this->authorize($model, $requiredPrivileges) && $requiredPrivileges != 'none') {
                return new InvalidSessionRenderer($this->args['is_api'], "You do not have permissions to perform this action. [" . htmlspecialchars((string) $model, ENT_QUOTES) . ", " . htmlspecialchars((string) $requiredPrivileges, ENT_QUOTES) . "]");
            } else {
                return false;
            }
        } else {
            return new InvalidSessionRenderer($this->args['is_api'], "Missing privileges in the Privileges Map for the requested action");
        }
    }

    private function checkExclude($route)
    {
        // Match an exclude entry exactly, or as a leading path segment so that
        // tokenised public routes work (e.g. entry "inv" excludes "inv/<token>"
        // and "inv/<token>/pdf"; "t/pixel" excludes "t/pixel/<token>.gif"). The
        // trailing-slash boundary prevents "inv" from matching "invoice/...".
        foreach ($this->privilegeMap['exclude'] as $entry) {
            if ($route === $entry || strpos($route, $entry . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Try to get an equivalent action in the privilege map
     *
     * @param String $action
     * @return String|false
     */
    private function getRequiredPrivilege(string $action, string $model = '')
    {
        if (! empty($this->privilegeMap['action'][$action])) {
            return $this->privilegeMap['action'][$action];
        } elseif (! empty($this->privilegeMap['action'][$model . "-" . $action])) {
            return $this->privilegeMap['action'][$model . "-" . $action];
        } else {
            return false;
        }
    }
}
