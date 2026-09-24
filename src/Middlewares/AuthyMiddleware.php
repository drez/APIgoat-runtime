<?php
namespace ApiGoat\Middlewares;

use ApiGoat\Api\ApiResponse;
use ApiGoat\Handlers\InvalidSessionRenderer;
use ApiGoat\Sessions\AuthySession;
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

    public function __construct(?ResponseFactoryInterface $responseFactory = null)
    {
        $this->privilegeMap = (require _BASE_DIR . "config/privileges.map.php");
    }

    /** Response header carrying the outcome of an impersonation (iarc) switch. */
    public const IARC_HEADER = 'X-Gc-Iarc';

    /** null = no iarc in this request; 'switched' | 'refused' otherwise. */
    private ?string $iarcOutcome = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->iarcOutcome = null;
        $response = $this->processRequest($request, $handler);
        // The template reads the switch outcome (GuiManager 'alive' and any
        // other route carrying iarc); status codes are left untouched.
        return self::withIarcOutcome($response, $this->iarcOutcome);
    }

    /** Stamp X-Gc-Iarc when a switch was attempted. Pure, unit-tested. */
    public static function withIarcOutcome(ResponseInterface $response, ?string $outcome): ResponseInterface
    {
        if ($outcome !== 'switched' && $outcome !== 'refused') {
            return $response;
        }
        return $response->withHeader(self::IARC_HEADER, $outcome);
    }

    private function processRequest(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->args = $request->getAttribute('parsed_args');

        // OAuth discovery documents (RFC 8414 / 9728) are inherently public — they
        // must be reachable without a CRM session so an MCP client can bootstrap.
        // Exact documents only (RoutePath) — a substring test exempted any
        // catch-all model route carrying /.well-known/ in its trailing params.
        if (RoutePath::isOAuthDiscovery($request->getUri()->getPath())) {
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

            if ($this->staleSessionCheck($request)) {
                // $access was judged for the session as it was: a logged-out
                // session must hit the login gate below, a rebuilt one its
                // new grants.
                $access = $this->checkPrivileges($request);
            }

            if ($_SESSION[_AUTH_VAR]->get('connected') != 'YES' && $access) {

                // Only the registered oauth/* routes run anonymously (exact
                // match — an action or model segment named "oauth" on any
                // catch-all model route used to skip the login check).
                if (! RoutePath::isOAuthRoute($request->getUri()->getPath())) {
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
        } else {
            $hasBearer = stripos($request->getHeaderLine('Authorization'), 'Bearer ') === 0
                || stripos($request->getHeaderLine('X-Authorization'), 'Bearer ') === 0;
            if (! $hasBearer) {
                // A cookie session on an /api/v* route is the same browser
                // session as the GUI: it gets the same stale-session re-check
                // (it used to be skipped for every is_api request).
                if ($this->staleSessionCheck($request)) {
                    $access = $this->checkPrivileges($request);
                }
            } elseif (is_object($_SESSION[_AUTH_VAR] ?? null)
                && \ApiGoat\Auth\AccountSecurity::tokenEpochMismatch($request->getAttribute('jwt_claims'), $_SESSION[_AUTH_VAR])) {
                // An app JWT minted before a password change / deactivation
                // (its `sep` claim != the row's session_epoch): the session was
                // hydrated from the row just now, so this is the only place the
                // difference shows. Refuse it like an expired token.
                $_SESSION[_AUTH_VAR] = \ApiGoat\Auth\AccountSecurity::signedOutSession(null);
                $access = $this->checkPrivileges($request);
            }
        }
        if ($this->args['is_api'] && $_SESSION[_AUTH_VAR]->get('connected') != 'YES' && ! $this->checkExclude($this->args['route']) && ! RoutePath::isOAuthRoute($request->getUri()->getPath())) {
            $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => ['Authentication required']]);
            $ApiResponse->setStatus(401);
            return $ApiResponse->getResponse();
        }

        $csrfFailure = $this->checkCsrf($request);
        if ($csrfFailure !== null) {
            return $csrfFailure;
        }

        $unsafeGet = $this->checkMutatingGet($request);
        if ($unsafeGet !== null) {
            return $unsafeGet;
        }

        // A switch changes who is asking: re-judge the route as the new user
        // ($access was computed for the pre-switch session above).
        if ($this->checkUserSwitch($request)) {
            $access = $this->checkPrivileges($request);
        }

        // After the switch, so an impersonating root can switch back from a
        // target the gate refuses; the gate then judges the switched session.
        if (self::backendDenied(
            ! empty(\ApiGoat\Utility\Settings::load()['backend_admin_only']),
            $_SESSION[_AUTH_VAR],
            $this->args,
            $this->privilegeMap['exclude'] ?? []
        )) {
            $message = _('The admin panel is reserved for administrators.');
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => [$message]]);
                $ApiResponse->setStatus(403);
                return $ApiResponse->getResponse();
            }
            // Impersonating: offer the way back (a switch runs before this gate).
            // The switch token travels in a POST body to the privilege-excluded
            // GuiManager 'alive' endpoint (checkUserSwitch reads args['data'] on
            // any route), never in a URL — a GET admin?iarc=…&iarc_csrf=… link
            // left it in history, access logs and the Referer.
            $switchBack = null;
            $impersonator = self::impersonatorId($_SESSION[_AUTH_VAR]);
            if ($impersonator !== null) {
                $switchBack = [
                    'action' => _SUB_DIR_URL . 'GuiManager',
                    'land'   => _SUB_DIR_URL . 'admin',
                    'iarc'   => $impersonator,
                    'csrf'   => (string) ($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] ?? ''),
                ];
            }
            $response = new Response();
            $sessCsrf = method_exists($_SESSION[_AUTH_VAR], 'getCsrf') ? (string) $_SESSION[_AUTH_VAR]->getCsrf() : '';
            $response->getBody()->write(self::backendDeniedPage($message, (string) $_SESSION[_AUTH_VAR]->get('username'), $switchBack, $sessCsrf));
            return $response->withHeader('Cache-Control', 'no-store')->withStatus(403);
        }


       // $access = $this->checkPrivileges($request);
        if (false !== $access) {
            // access denied
            if ($access instanceof InvalidSessionRenderer) {
                if ($this->args['is_api']) {
                    $ApiResponse = new ApiResponse($this->args, $this->response, (($access->getMessage()) ? $access->getMessage() : []));
                    $ApiResponse->setStatus(403);
                    return $ApiResponse->getResponse();
                } else {
                    // A denial is terminal: the route handler must NOT run.
                    // It used to be invoked and its response discarded — but
                    // the action had already executed (and legacy handlers
                    // that echo + exit even delivered their output), so any
                    // custom action ran for a user lacking the right.
                    error_log('gc: access denied ' . $request->getMethod() . ' '
                        . $this->args['model'] . '/' . $this->args['action']
                        . ' for authy#' . (int) $_SESSION[_AUTH_VAR]->getIdAuthy());
                    $response = new Response();
                    $response->getBody()->write($access->getMessage());
                    return $response->withHeader('Cache-Control', 'no-store')->withStatus(403);
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
     * Stale-session guard: a session can outlive its user (DB reseed, deleted
     * account), its grants and its credentials. Re-judge it against the authy
     * row: gone, deactivated, expired or its session_epoch re-rolled (password
     * change, token revocation) → sign the session out (the caller's login gate
     * answers 303/401); rights / groups / root / tenant changed
     * (AuthySession::rightsFingerprint) → rebuild the grants in place, so a
     * revoked right or a removed admin group stops applying without waiting
     * for the session to expire. A DB error (query throws) must NOT log anyone
     * out. Every state-changing request re-checks; GETs at most once a minute,
     * or at once when a published epoch marker says this session is behind
     * (AccountSecurity::sessionEpochStale — an APCu read, no DB).
     *
     * @return bool true when the session changed (logged out or rebuilt), so
     *              the caller re-judges $access
     */
    private function staleSessionCheck(ServerRequestInterface $request): bool
    {
        $sess = $_SESSION[_AUTH_VAR] ?? null;
        if (! $sess instanceof AuthySession || $sess->get('connected') != 'YES' || ! $sess->getIdAuthy()) {
            return false;
        }
        $recheck = $request->getMethod() !== 'GET'
            || (time() - (int) $sess->get('stale_check_ts')) > 60
            || \ApiGoat\Auth\AccountSecurity::sessionEpochStale($sess);
        if (! $recheck) {
            return false;
        }
        $verdict = 'ok';
        try {
            $authy = \App\AuthyQuery::create()->findPk($sess->getIdAuthy());
            $verdict = $authy === null
                ? 'logout'
                : $sess->revalidate($authy, AuthySession::loadGroupState($authy));
        } catch (\Exception $e) { /* DB transient: keep the session, don't lock out */ }
        if ($verdict === 'logout') {
            error_log('gc: stale session signed out for authy#' . (int) $sess->getIdAuthy());
            // Keep the per-browser csrf token + language so the page's re-auth
            // modal can sign straight back in.
            $_SESSION[_AUTH_VAR] = \ApiGoat\Auth\AccountSecurity::signedOutSession($sess);
        } else {
            $sess->set('stale_check_ts', time());
        }
        return $verdict !== 'ok';
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
    /**
     * OAuth 2.1 PROTOCOL endpoints that are authenticated by what is IN the
     * request (PKCE code + verifier + client_id, refresh token, or nothing for
     * rate-limited dynamic registration) and never read the ambient session
     * as a credential — so there is no forgeable cookie privilege to protect.
     * Native clients (React Native okhttp) re-send the session cookie set on
     * earlier calls; a still-connected session made the token POST look like a
     * session-authenticated write and 403'd it without an OAuth `error` key.
     * Exact-route match only. /oauth/authorize is deliberately NOT here: the
     * consent form is a genuine session-authenticated browser POST.
     */
    private const CSRF_EXEMPT_ROUTES = ['oauth/token', 'oauth/register'];

    private function checkCsrf(ServerRequestInterface $request): ?ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (in_array(trim((string) ($this->args['route'] ?? ''), '/'), self::CSRF_EXEMPT_ROUTES, true)) {
            return null;
        }
        // SECURITY (review R5): exempt only genuine Bearer-token requests (no
        // ambient cookie credential to forge), NOT every is_api route. A
        // cookie-authenticated write to an API route must still carry the CSRF
        // token — the first-party client already attaches it to all non-GET.
        //
        // Review-3 Wave 3: the header alone is not enough. A connected COOKIE
        // session plus any "Authorization: Bearer x" skipped JwtAuthentication
        // (it short-circuits on connected==='YES') and then skipped this gate
        // too, so the request ran on the ambient cookie with no token check at
        // all. Only a bearer that actually authenticated is exempt.
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || self::bearerAuthenticated($request, $_SESSION[_AUTH_VAR] ?? null)
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

    /** Request attribute OAuthResourceMiddleware sets when an RS256 bearer hydrated the session. */
    public const ATTR_BEARER_AUTH = 'gc_bearer_auth';

    /**
     * Did the request's Bearer token actually authenticate it? Only then is it
     * free of the ambient-cookie CSRF / mutating-GET gates.
     *
     *  - OAuth RS256: OAuthResourceMiddleware marks ATTR_BEARER_AUTH on success;
     *  - app HS256: JwtAuthentication's before-handler sets jwt_claims once the
     *    signature verified;
     *  - a CONNECTED cookie session + HS256 bearer: JwtAuthentication skipped
     *    the token (connected already), so verify it here and require it to
     *    belong to the session's user (a mobile client whose cookie jar kept
     *    the login session keeps working; a junk header does not exempt).
     */
    public static function bearerAuthenticated(ServerRequestInterface $request, $session): bool
    {
        $line = $request->getHeaderLine('Authorization');
        if (stripos($line, 'Bearer ') !== 0) {
            return false;
        }
        if ($request->getAttribute(self::ATTR_BEARER_AUTH) === true) {
            return true;
        }
        $claims = $request->getAttribute('jwt_claims');
        if (is_array($claims) && $claims !== []) {
            return true;
        }
        $token  = trim(substr($line, 7));
        $secret = function_exists('env') ? env('JWT_SECRET') : getenv('JWT_SECRET');
        if ($token === '' || !is_string($secret) || $secret === ''
            || !class_exists(\Firebase\JWT\JWT::class) || !is_object($session)) {
            return false;
        }
        try {
            $decoded = (array) \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return false;
        }
        $id = (int) ($decoded['authyId'] ?? (ctype_digit((string) ($decoded['sub'] ?? '')) ? $decoded['sub'] : 0));
        return $id > 0 && (int) $session->get('id') === $id;
    }

    /**
     * SECURITY: refuse a state-changing action requested over GET.
     *
     * The generated HTML route is `[/{a}[/{params}]]` registered for GET *and*
     * POST, and the emitted Service::getResponse() dispatches delete / update /
     * insert / BUsave / mass / prune / NtNsave* / upload / quickadd … straight
     * off $request['a'] without ever looking at the HTTP method. The session
     * cookie is SameSite=Lax, which still sends the cookie on a cross-site
     * TOP-LEVEL navigation — so `<a href="https://app/Contact/delete/42">` in an
     * email deleted the record with no token in sight. checkCsrf() could not
     * catch it: it passes anything that is not POST/PUT/PATCH/DELETE.
     *
     * Scope: cookie-authenticated GET/HEAD on an HTML route only. Bearer-token
     * requests keep their existing exemption (no ambient cookie to forge), and
     * so do routes excluded from privilege checks. Legitimate GET reads (list,
     * edit, view, autoc, search, printable, pdfdownload, fieldvals,
     * summarycards, childLinkSearch, dateCascadePeek, file/open, and the whole
     * Authy login / logout / reset / resetConfirm / confirm / register flow)
     * are not on Service::MUTATING_ACTIONS and pass untouched.
     *
     * @return ResponseInterface|null a 405 response, or null when allowed
     */
    private function checkMutatingGet(ServerRequestInterface $request): ?ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }
        if (! empty($this->args['is_api'])) {
            return null;
        }
        if (self::bearerAuthenticated($request, $_SESSION[_AUTH_VAR] ?? null)) {
            return null;
        }
        if ($_SESSION[_AUTH_VAR]->get('connected') != 'YES') {
            return null;
        }
        if ($this->checkExclude($this->args['route'])
            || in_array(trim((string) ($this->args['route'] ?? ''), '/'), self::CSRF_EXEMPT_ROUTES, true)) {
            return null;
        }

        // RouteParser::decodePath() puts the {a} URL segment on 'action'
        // ('a' is only populated later, inside the route closure by RouteHelper).
        $action = (string) ($this->args['action'] ?? ($this->args['a'] ?? ''));
        // A route that leaves 'a' to the query (RouteHelper keeps a client
        // ?a= whenever the path pinned none) dispatches on THAT value, while
        // the parsed path action reads 'list' — so /Model?a=delete walked
        // past this gate and only the emitted service guard stood in the way.
        // Either name being a write refuses the GET.
        $queryAction = $request->getQueryParams()['a'] ?? '';
        $queryAction = is_string($queryAction) ? $queryAction : '';
        if (! \ApiGoat\Services\Service::isMutatingAction($action)) {
            if (! \ApiGoat\Services\Service::isMutatingAction($queryAction)) {
                return null;
            }
            $action = $queryAction;
        }

        // No exemptions: a mutating action is POST-only. The first-party GET
        // escapes this branch used to allow (generatepdf via window.open;
        // opengdrive / stripecheckout / stripecharge via an XHR-marked GET
        // fetch) went away when the template client switched them to POST
        // (F3, review #13).
        error_log('mutating GET refused: ' . ($this->args['route'] ?? '')
            . ' action=' . $action
            . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
            . ' uid=' . $_SESSION[_AUTH_VAR]->get('id'));

        $ApiResponse = new ApiResponse($this->args, $this->response, ['status' => 'failure', 'data' => null, 'errors' => ['This action requires POST']]);
        $ApiResponse->setStatus(405);
        return $ApiResponse->getResponse();
    }

    /**
     * The root user behind an impersonated session, or null. Set only by a
     * switch (checkUserSwitch); it is what lets the impersonated session
     * switch again or back while carrying the TARGET's real rights.
     */
    public static function impersonatorId($session): ?int
    {
        if (! is_object($session) || ! isset($session->sessVar) || ! is_array($session->sessVar)) {
            return null;
        }
        $id = (int) ($session->sessVar['ImpersonatorId'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Root, or a session a root switched into: may use the impersonate switch. */
    public static function canSwitchUser($session): bool
    {
        return is_object($session) && ($session->get('isRoot') || self::impersonatorId($session) !== null);
    }

    private function checkUserSwitch($request): bool
    {
        $switched = $this->attemptUserSwitch($request);
        if (! empty($this->args['data']['iarc'])) {
            $this->iarcOutcome = $switched ? 'switched' : 'refused';
        }
        return $switched;
    }

    private function attemptUserSwitch($request): bool
    {
        if (! self::canSwitchUser($_SESSION[_AUTH_VAR])) {
            return false;
        }

        if (empty($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'])) {
            $_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] = bin2hex(random_bytes(16));
        }
        if (empty($_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'])) {
            $_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'] = $_SESSION[_AUTH_VAR]->get('id');
        }

        if (! isset($this->args['data']['iarc']) || ! $this->args['data']['iarc']) {
            return false;
        }

        $submittedCsrf = $this->args['data']['iarc_csrf'] ?? '';
        $sessionCsrf   = (string) ($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] ?? '');
        if ($submittedCsrf === '' || $sessionCsrf === '' || ! hash_equals($sessionCsrf, (string) $submittedCsrf)) {
            error_log('iarc switch rejected: csrf mismatch from ' . $_SERVER['REMOTE_ADDR'] . ' uid=' . $_SESSION[_AUTH_VAR]->get('id'));
            return false;
        }

        $authyObj = \App\AuthyQuery::create()->findPk($this->args['data']['iarc']);
        if (! $authyObj || ! $authyObj->getIdAuthy()) {
            return false;
        }

        // The root behind this switch: the impersonator when already switched,
        // else the (root) session user. Re-checked against the DB on every
        // switch, so a demoted root loses the switch even mid-impersonation.
        $originalRootId = self::impersonatorId($_SESSION[_AUTH_VAR]) ?? (int) $_SESSION[_AUTH_VAR]->get('id');
        $rootObj        = \App\AuthyQuery::create()->findPk($originalRootId);
        if (! $rootObj || $rootObj->getIsRoot() !== 'Yes') {
            error_log('iarc switch rejected: impersonator ' . $originalRootId . ' is not root');
            unset($_SESSION[_AUTH_VAR]->sessVar['ImpersonatorId']);
            return false;
        }
        $targetUsername = $authyObj->getUsername();

        // The switched session carries the TARGET's real rights (root only if
        // the target is root) — it used to force isRoot=true, so a root viewing
        // "as" a member bypassed every row scope and the backend_admin_only
        // gate and never saw what that user sees. Only the impersonator id is
        // kept, to allow switching again or back.
        $AuthyForm = new \App\AuthyService($request, null, $this->args['data']);
        $AuthyForm->setSession($authyObj, $targetUsername);
        if ((int) $authyObj->getIdAuthy() !== $originalRootId) {
            $_SESSION[_AUTH_VAR]->sessVar['ImpersonatorId'] = $originalRootId;
        } else {
            unset($_SESSION[_AUTH_VAR]->sessVar['ImpersonatorId']);
        }
        $_SESSION[_AUTH_VAR]->sessVar['IdAuthy']        = (int) $authyObj->getIdAuthy();
        $_SESSION[_AUTH_VAR]->sessVar['OriginalRootId'] = $originalRootId;
        // Rotate: a used switch token must not be replayable (un-upgraded
        // clients may still send it in a GET URL).
        $_SESSION[_AUTH_VAR]->sessVar['IarcCsrf']       = bin2hex(random_bytes(16));

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
        return true;
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
        //
        // Project-declared self-service ACTIONS (settings `self_service_models`,
        // shape `['Model' => ['actionOne', 'actionTwo', ...]]`): a project's own
        // custom non-CRUD service (e.g. apigTutor's RealtimeService/TutorService)
        // is not a Propel model, so authorize($model, ...) can never succeed for
        // it and would lock out every non-root caller. A shared runtime can't
        // grow a per-project hardcoded list, hence the settings hook.
        //
        // SECURITY (review I1): this is deliberately ACTION-granular, not
        // model-granular — a declared model exempts ONLY the actions it lists,
        // mirroring the ApiGoat/geocode precedent just below (scoped to two
        // named actions) rather than widening to "every action under this URL
        // segment". A model-only exemption would have let a single typo/
        // copy-paste (e.g. declaring a real Propel model name by mistake)
        // silently drop RBAC from that model's entire CRUD surface with no
        // error. self::isProjectSelfServiceAction() additionally refuses (and
        // error_logs) any declared name that resolves to a real emitted model,
        // as a second guard against exactly that mistake.
        //
        // This grants exemption from the model-RBAC-matrix check ONLY — being
        // authenticated is still required (enforced above), and the service
        // itself is responsible for any further authorization (e.g. apigTutor's
        // RealtimeService/TutorService verify conversation ownership — see
        // LearnerResolver in that project).
        if (in_array(strtolower((string) $this->args['model']), ['account', 'oauth', '_meta', 'push'], true)) {
            return false;
        }
        if (self::isProjectSelfServiceAction((string) $this->args['model'], (string) $this->args['action'])) {
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
            //
            // READ_ONLY_POST_ACTIONS are the emitter's own actions that are
            // POSTed yet only read (the service is never instantiated here, so
            // its $readOnlyCustomActions cannot be consulted): inferring 'w'
            // locked them away from every user holding just 'r'.
            $model   = $this->args['model'];
            $reqMethod = strtoupper($request->getMethod());
            $requiredPrivileges = self::inferredPrivilege($reqMethod, (string) $this->args['action']);
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

    /**
     * Emitter actions that arrive by POST but only READ, so they need 'r', not
     * the 'w' a POST otherwise implies. Each handler re-checks
     * hasRights(model,'r') itself and persists nothing:
     *   chat      — with_ai: answers from the ContextProvider (POST for the body)
     *   selectbox — ChildSelect cascade: option list of one dependent select
     * Keep this list to actions the EMITTER owns; a project's own read-only
     * POST action belongs in its privilege map.
     */
    public const READ_ONLY_POST_ACTIONS = ['chat', 'selectbox'];

    /** Right inferred for a custom action absent from the privilege map (pure; unit-tested). */
    public static function inferredPrivilege(string $method, string $action): string
    {
        if (in_array(strtolower(trim($action)), self::READ_ONLY_POST_ACTIONS, true)) {
            return 'r';
        }
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'w' : 'r';
    }

    /**
     * True when $model/$action matches a project-declared self-service entry
     * (settings `self_service_models = ['Model' => ['action', ...], ...]`).
     * Case-insensitive on both the model and action names. A declared model
     * name that resolves to a REAL emitted Propel model is refused (and
     * logged loudly) rather than honored — see the review-I1 comment at the
     * call site for why. Bit-identical to "no exemption" when the setting is
     * absent (returns false immediately).
     */
    public static function isProjectSelfServiceAction(string $model, string $action): bool
    {
        $map = \ApiGoat\Utility\Settings::load()['self_service_models'] ?? null;
        if (!is_array($map) || $model === '') {
            return false;
        }
        $modelLower = strtolower($model);
        $actionLower = strtolower($action);
        foreach ($map as $declaredModel => $actions) {
            if (!is_string($declaredModel) || strtolower($declaredModel) !== $modelLower || !is_array($actions)) {
                continue;
            }
            if (class_exists('\\App\\' . $declaredModel . 'Query')) {
                error_log('[gc-self-service] self_service_models declares "' . $declaredModel
                    . '", which resolves to a real Propel model (\\App\\' . $declaredModel . 'Query exists) — '
                    . 'ignoring the exemption for it. This would otherwise silently drop RBAC from that '
                    . "model's entire CRUD surface for every authenticated user.");
                continue;
            }
            foreach ($actions as $declaredAction) {
                if (is_string($declaredAction) && strtolower($declaredAction) === $actionLower) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Session routes a non-admin may still reach under backend_admin_only:
     * signing in/out and the account-recovery flows, whose pages run through
     * this middleware with a live session.
     */
    public const BACKEND_OPEN_AUTHY_ACTIONS = ['login', 'auth', 'logout', 'register', 'google', 'reset', 'resetconfirm', 'confirm'];

    /**
     * settings `backend_admin_only` (opt-in per project): the session backend
     * belongs to Admin-group and root users. A marketplace hands every
     * self-registered member a session login plus Owner rights meant for the
     * app/API — without this they could browse the admin panel. API routes
     * (RBAC-governed), OAuth consent, the privilege-map exclude list and
     * sign-in/out stay open; an anonymous session is left to the login redirect.
     */
    public static function backendDenied(bool $adminOnly, $session, array $args, array $exclude = []): bool
    {
        if (! $adminOnly || ! empty($args['is_api']) || ! is_object($session)) {
            return false;
        }
        if ($session->get('connected') !== 'YES' || $session->isAdmin() || $session->isRoot()) {
            return false;
        }
        $model  = strtolower((string) ($args['model'] ?? ''));
        $action = strtolower((string) ($args['action'] ?? ''));
        $route  = (string) ($args['route'] ?? '');
        // Exact oauth/<endpoint> route only: a model or action SEGMENT named
        // "oauth" on a catch-all route (Product/oauth) must not open the backend.
        if (preg_match('#^oauth/[^/]+/?$#i', $route)) {
            return false;
        }
        if ($model === 'authy' && in_array($action, self::BACKEND_OPEN_AUTHY_ACTIONS, true)) {
            return false;
        }
        foreach ($exclude as $entry) {
            if ($route === $entry || strpos($route, $entry . '/') === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * The backend_admin_only 403 page: a self-contained card (inline styles,
     * no layout/asset dependency — the gate runs before any page rendering),
     * with the project's admin logo when it has one.
     *
     * $switchBack (impersonating only): ['action' => GuiManager URL, 'land' =>
     * admin URL, 'iarc' => impersonator id, 'csrf' => IarcCsrf]. Rendered as a
     * POST form (token in the body, never the URL); a nonced script submits it
     * with fetch and then lands on 'land' — no inline handler (CSP is
     * nonce-only), no native dialog. Without JS the native POST still switches.
     *
     * "Log out" is a POST form carrying the session csrf token ($csrf): the
     * emitted Authy logout (goatcheese 69de5f6) honours a GET only for a
     * same-origin navigation and otherwise answers with a confirm page.
     */
    public static function backendDeniedPage(string $message, string $username, ?array $switchBack, string $csrf = ''): string
    {
        $e    = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $logo = (\defined('_BASE_DIR') && is_file(_BASE_DIR . 'public/img/logo-admin.png'))
            ? '<img src="' . $e(_SUB_DIR_URL . 'public/img/logo-admin.png') . '" alt="" style="max-height:44px;max-width:180px;margin-bottom:20px;">'
            : '';
        $who  = $username !== ''
            ? '<p style="margin:0 0 24px;color:#697386;font-size:14px;">' . sprintf($e(_('Signed in as %s')), '<strong style="color:#0a2540;">' . $e($username) . '</strong>') . '</p>'
            : '';
        $btn  = 'display:block;padding:11px 16px;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;text-align:center;';
        $logout = static fn (string $style): string =>
            '<form method="post" action="' . $e(_SUB_DIR_URL . 'Authy/logout') . '" style="margin:0;">'
            . '<input type="hidden" name="csrf" value="' . $e($csrf) . '">'
            . '<button type="submit" style="' . $btn . 'width:100%;border:0;cursor:pointer;font-family:inherit;' . $style . '">' . $e(_('Log out')) . '</button>'
            . '</form>';
        $script = '';
        if ($switchBack !== null) {
            $nonce   = \function_exists('gcNonceAttr') ? gcNonceAttr() : '';
            $primary = '<form id="gc-iarc-back" method="post" action="' . $e($switchBack['action'] ?? '') . '" data-land="' . $e($switchBack['land'] ?? '') . '" style="margin:0;">'
                . '<input type="hidden" name="a" value="alive">'
                . '<input type="hidden" name="iarc" value="' . $e($switchBack['iarc'] ?? '') . '">'
                . '<input type="hidden" name="iarc_csrf" value="' . $e($switchBack['csrf'] ?? '') . '">'
                . '<button type="submit" style="' . $btn . 'width:100%;border:0;cursor:pointer;font-family:inherit;background:#0a2540;color:#fff;">' . $e(_('Stop impersonating')) . '</button>'
                . '</form>'
                . '<p id="gc-iarc-err" hidden style="margin:10px 0 0;color:#c0392b;font-size:14px;">' . $e(_('Could not switch back — please try again.')) . '</p>'
                . $logout('margin-top:10px;background:#f4f6f8;color:#0a2540;');
            $script = '<script' . $nonce . '>(function(){var f=document.getElementById("gc-iarc-back");'
                . 'if(!f||!window.fetch||!window.URLSearchParams||!window.FormData){return;}'
                . 'f.addEventListener("submit",function(ev){ev.preventDefault();var b=f.querySelector("button");if(b){b.disabled=true;}'
                . 'fetch(f.action,{method:"POST",credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"},body:new URLSearchParams(new FormData(f))})'
                . '.then(function(r){if(!r.ok){throw new Error("switch");}window.location.href=f.getAttribute("data-land");})'
                . '.catch(function(){if(b){b.disabled=false;}var m=document.getElementById("gc-iarc-err");if(m){m.hidden=false;}});});}());</script>';
        } else {
            $primary = $logout('background:#0a2540;color:#fff;');
        }

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . $e(_('Access restricted')) . '</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;padding:16px;box-sizing:border-box;">'
            . '<div style="width:100%;max-width:400px;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(10,37,64,.08);padding:32px;box-sizing:border-box;text-align:center;">'
            . $logo
            . '<div style="width:48px;height:48px;margin:0 auto 16px;border-radius:50%;background:#fdecea;color:#c0392b;font-size:24px;line-height:48px;font-weight:700;">!</div>'
            . '<h1 style="margin:0 0 8px;font-size:20px;color:#0a2540;">' . $e(_('Access restricted')) . '</h1>'
            . '<p style="margin:0 0 8px;color:#425466;font-size:15px;line-height:1.5;">' . $e($message) . '</p>'
            . $who
            . $primary
            . '</div>' . $script . '</body></html>';
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
