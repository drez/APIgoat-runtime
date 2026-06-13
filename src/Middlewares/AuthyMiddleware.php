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
            if ($_SESSION[_AUTH_VAR]->get('connected') == 'YES' && $_SESSION[_AUTH_VAR]->getIdAuthy()) {
                try {
                    if (\App\AuthyQuery::create()->findPk($_SESSION[_AUTH_VAR]->getIdAuthy()) === null) {
                        unset($_SESSION[_AUTH_VAR]);
                        $_SESSION[_AUTH_VAR] = new AuthySession();
                        $_SESSION[_AUTH_VAR]->set('isConnected', 'NO');
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
        if ($this->args['is_api']
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

        $requiredPrivileges = $this->getRequiredPrivilege($this->args['action'], $this->args['model']);
        if ($requiredPrivileges === false) {
            // custom privileges
            $model              = $this->args['model']; // . '-' . $this->args['action'];
            $requiredPrivileges = 'r';
        } else {
            $model = $this->args['model'];
        }

        if(empty($model)){
            return false;
        }

        if (! empty($requiredPrivileges)) {
            if (! $this->authorize($model, $requiredPrivileges) && $requiredPrivileges != 'none') {
                return new InvalidSessionRenderer($this->args['is_api'], "You do not have permissions to perform this action. [" . $model . ", " . $requiredPrivileges . "]");
            } else {
                return false;
            }
        } else {
            return new InvalidSessionRenderer($this->args['is_api'], "Missing privileges in the Privileges Map for the requested action");
        }
    }

    private function checkExclude($route)
    {
        if (in_array($route, $this->privilegeMap['exclude'])) {
            return true;
        }
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
