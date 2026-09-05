<?php

namespace ApiGoat\Services;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ApiGoat\Utility\BuilderLayout;
use ApiGoat\Utility\BuilderMenus;
use ApiGoat\Api\ApiResponse;
/*
 * Base class for custom services
 * 
 */

/**
 * Description of Service
 *
 * @author sysadmin
 */
class Service
{
    /**
     * Every `case '<a>':` the emitter dispatches from getResponse() that
     * WRITES, lowercased (the match is case-insensitive). Single source of
     * truth for "is this URL segment a mutation" — used by
     * AuthyMiddleware to refuse a cookie-authenticated mutating GET, because
     * the generated HTML route `[/{a}[/{params}]]` is registered for GET as
     * well as POST and getResponse() dispatches on $request['a'] regardless of
     * method (so with SameSite=Lax a cross-site <a href> deleted records).
     *
     * Derived by grepping the emitter for the case labels reachable from
     * getResponse():
     *   goatcheese/Classes/Service.php        insert, update, delete, BUsave,
     *                                         BUsave{Child}, mass (massBulkUpdate),
     *                                         uploadFile, newFolder, clone
     *   Classes/include/childListBuilder.php  NtNsave{Child}
     *   Parameters/add_mass_action.php        mass
     *   Parameters/add_prune_action.php       prune
     *   Parameters/set_quick_add.php          quickadd
     *   Parameters/is_file_upload_table.php   upload   (NOT file/open — both
     *                                         return getFileContent(), reads)
     *   Parameters/set_child_link.php         childLinkSave, childUnlink
     *                                         (childLinkSearch is a read)
     *   Parameters/with_pdf.php               generatepdf, opengdrive
     *                                         (printable/pdfdownload/pdf are reads)
     *   Parameters/with_stripe.php            stripecheckout, stripecharge,
     *                                         striperefund, stripepush
     *                                         (stripestatus is a read)
     *   Parameters/with_ai.php                chat
     *
     * Deliberately OUT: every read (list, edit, view, autoc, search, printable,
     * pdfdownload, fieldvals, summarycards, dateCascadePeek, childLinkSearch,
     * file, open) and the Authy pre-auth flows (login, logout, auth, google,
     * reset, resetConfirm, register, confirm) — those are unauthenticated or
     * GET-by-design (the emailed confirm/reset links, the logout href) and
     * carry their own single-use tokens.
     *
     * @var string[]
     */
    public const MUTATING_ACTIONS = [
        'insert',
        'update',
        'create',
        'delete',
        'clone',
        'busave',
        'mass',
        'massbulkupdate',
        'prune',
        'quickadd',
        'upload',
        'uploadfile',
        'newfolder',
        'childlinksave',
        'childunlink',
        'generatepdf',
        'opengdrive',
        'stripecheckout',
        'stripecharge',
        'striperefund',
        'stripepush',
        'chat',
    ];

    /**
     * Per-child variants the emitter suffixes with the child PhpName:
     * `NtNsave{Child}` (is_cross_ref) and `BUsave{Child}` (child bulk update).
     * Lowercased prefixes.
     *
     * @var string[]
     */
    public const MUTATING_ACTION_PREFIXES = ['ntnsave', 'busave'];

    /**
     * Mutations the FIRST-PARTY client legitimately reaches over GET, as XHR:
     * template .admin/public/js/app/pdfmenu.js and app/stripe.js call them with
     * fetch(..., {headers:{'X-Requested-With':'XMLHttpRequest'}}). That header
     * cannot be set by a cross-site <a href>/<img>/<form> navigation, and a
     * cross-origin fetch that tried would be preflighted (CorsMiddleware allows
     * no credentialed CORS) — so requiring it keeps the CSRF property while the
     * shipped UI keeps working. Move an entry out of here as soon as the client
     * switches it to POST.
     *
     * @var string[]
     */
    public const GET_XHR_MUTATIONS = ['opengdrive', 'stripecheckout', 'stripecharge'];

    /**
     * Mutations the first-party client reaches by a TOP-LEVEL GET navigation —
     * pdfmenu.js does `window.open(<model>/generatepdf?i=…)`, which carries no
     * XHR header. Unconditionally exempt from the mutating-GET refusal, so this
     * list must stay minimal and low-impact: generatepdf only (re)renders the
     * saved PDF of a record the caller can already read.
     *
     * @var string[]
     */
    public const GET_NAV_MUTATIONS = ['generatepdf'];

    /**
     * Does this URL action segment dispatch to a write?
     */
    public static function isMutatingAction(?string $a): bool
    {
        $a = strtolower(trim((string) $a));
        if ($a === '') {
            return false;
        }
        if (in_array($a, self::MUTATING_ACTIONS, true)) {
            return true;
        }
        foreach (self::MUTATING_ACTION_PREFIXES as $prefix) {
            if ($a !== $prefix && strncmp($a, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this mutating action one the FIRST-PARTY client legitimately reaches
     * over GET? The single decision shared by the middleware (AuthyMiddleware::
     * checkMutatingGet) and the defence-in-depth guard the emitter puts at the
     * top of every generated Service::getResponse(), so the two exemption sets
     * can never drift apart.
     *
     * GET_NAV_MUTATIONS are exempt unconditionally (a top-level window.open
     * carries no XHR header); GET_XHR_MUTATIONS only when the caller proved it
     * is a script-initiated same-origin call — a cross-site navigation can never
     * set X-Requested-With, and a cross-origin fetch that tried would be
     * preflighted away by CorsMiddleware.
     */
    public static function isGetExemptMutation(?string $a, bool $isXhr): bool
    {
        $a = strtolower(trim((string) $a));
        if ($a === '') {
            return false;
        }
        if (in_array($a, self::GET_NAV_MUTATIONS, true)) {
            return true;
        }

        return $isXhr && in_array($a, self::GET_XHR_MUTATIONS, true);
    }

    /**
     * Must this request be refused because it reaches a WRITE over GET?
     *
     * Args-based counterpart of AuthyMiddleware::checkMutatingGet(), for callers
     * that hold the RouteHelper $args array rather than the PSR-7 request — i.e.
     * the emitted Service::getResponse() (the generated controller), which the
     * emitter guards as defence in depth behind the middleware. Same action
     * inventory (isMutatingAction) and the same two exemption sets
     * (isGetExemptMutation) — nothing is re-derived here.
     *
     * $args['method'] is the route layer's TRUSTED method (RouteHelper snapshots
     * it before the user query/body merge and reasserts it afterwards, so a
     * ?method=POST can't steer this), and $args['a'] the path-derived action.
     *
     * The session check the middleware makes is deliberately absent: by the time
     * a generated service runs, AuthyMiddleware has already authenticated the
     * request, and a bearer/API caller is filtered out below.
     *
     * @param array $args    RouteHelper::getArgs() output ($this->request in the
     *                       emitted service)
     * @param mixed $request optional PSR-7 ServerRequestInterface for the header
     *                       reads (falls back to $_SERVER)
     */
    public static function mutatingGetRefusal(array $args, $request = null): bool
    {
        $method = strtoupper(trim((string) ($args['method'] ?? '')));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        // api/v1 routes are bearer-authenticated (no ambient cookie authority),
        // and they dispatch through getApiResponse(), not getResponse().
        if (! empty($args['is_api']) || ! empty($args['isApiCall'])) {
            return false;
        }

        $header = static function (string $name) use ($request): string {
            if (is_object($request) && method_exists($request, 'getHeaderLine')) {
                return (string) $request->getHeaderLine($name);
            }
            return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
        };

        if (stripos($header('Authorization'), 'Bearer ') === 0) {
            return false;
        }

        $action = (string) ($args['a'] ?? ($args['action'] ?? ''));
        if (! self::isMutatingAction($action)) {
            return false;
        }

        return ! self::isGetExemptMutation($action, $header('X-Requested-With') === 'XMLHttpRequest');
    }

    /**
     * return abstract
     * @var array|Response
     */
    public $content = ['html' => '', 'onReadyJs' => '', 'js' => '', 'json' => ''];
    /**
     *
     * @var BuilderLayout object
     */
    public $BuilderLayout;
    /**
     *
     * @var array
     */
    public $request;
    /**
     *
     * @var PSR-7 response object
     * immutable object
     */
    public $response;

    public $args = [];
    private $body;

    /**
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     */
    public function __construct(Request $request, Response $response, array $args)
    {
        $this->response = $response;

        $this->request = $request;
        $this->BuilderLayout = new BuilderLayout(new BuilderMenus($args));
        $this->args = $args;
        $this->args['i'] = $args['i'];
        $this->args['a'] = $args['a'];
        $this->args['p'] = $args['p'];
        $this->args['ip'] = (isset($args['ip'])?$args['ip']:null);
    }
    /**
     * Get the proper response
     * @return string
     */
    public function getResponse()
    {
        $this->body = ['html' => "Unknown method"];

        switch ($this->args['a']) {
            case '':
            case 'list':
                if (method_exists($this, 'list')) {
                    //$this->body = $this->list();
                }
                break;
            case 'edit':
                //$this->body = $this->edit();
                break;
            case 'update':
            case 'insert':
                //$this->body = $this->saveUpdate();
                return $this->BuilderLayout->renderXHR($this->content);
            case 'delete':
                //$this->body = $this->deleteOne();
                return $this->BuilderLayout->renderXHR($this->content);
            case 'upload':
                //$this->body = $this->file();
                return $this->BuilderLayout->renderXHR($this->content);
            case 'file':
            case 'open':
                //return $this->getFileContent();
        }
        if ($this->args['ui']) {
            return $this->BuilderLayout->renderXHR($this->body);
        } else {
            return $this->BuilderLayout->render($this->body);
        }
    }

    /**
     * Get the proper api response
     * @return array
     */
    public function getApiResponse()
    {
        $this->body = ['status' => 'failure', 'data' => null, 'errors' => ['Unknown method'], 'messages' => null];

        switch ($this->args['method']) {
            case 'AUTH':
                //$this->body = $this->auth();
                break;
            case 'GET':
                //$this->body = $this->getJson($this->args);
                break;
            case 'PATCH':
                //$this->body = $this->setJson($this->args);
                break;
            case 'PUT':
                // $this->body = $this->file($this->args);
                break;
            case 'DELETE':
                // $this->body = $this->setJson($this->args);
                break;
        }

        $ApiResponse = new ApiResponse($this->request, $this->response, $this->body);
        return $ApiResponse->getResponse();
    }
}
