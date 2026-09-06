<?php

namespace ApiGoat\Services;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ApiGoat\Utility\BuilderLayout;
use ApiGoat\Utility\BuilderMenus;
use ApiGoat\Api\ApiResponse;
use ApiGoat\Services\Concerns\HaltsResponses;
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
    use HaltsResponses;

    /**
     * Hard cap on how many rows one bulk/mass request may touch.
     *
     * The bulk-edit and mass-action endpoints take the selection straight from
     * the client, so without a cap a single POST could be made to load, validate
     * and save an unbounded number of rows one at a time (a cheap request-side
     * amplification into a very expensive server-side loop, and a transaction
     * long enough to hold locks across the whole table). 500 is well above any
     * realistic on-screen selection.
     */
    public const MAX_BULK_ROWS = 500;

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
     * `pdfdownload` is the one read that can WRITE — it fills the saved-PDF
     * cache on first access. Ruled a read and kept a GET (final review I-6):
     * download links are <a href>/window.open, the generating path is gated on
     * the caller holding `w` (Parameters/with_pdf.php gcPdfRecord('w')), the
     * result is idempotent and no user-visible record state changes. The first
     * generation is logged there so the side effect is auditable.
     *
     * PROJECT-DEFINED actions are NOT in this inventory and cannot be — see
     * $readOnlyCustomActions / customActionGetRefusal() below, which fail them
     * closed on a cookie-auth GET instead.
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
     * Decode the bulk-edit row selection carried by the panel's hidden `idPk`
     * field into a flat list of primary keys (F4, review #13).
     *
     * The list client builds the value as a urlencoded query string of the
     * checked row checkboxes — `check_<pk>=<pk>&check_<pk>=<pk>` — so the PKs
     * are the parsed VALUES. Two other shapes reach here and used to yield an
     * EMPTY selection (a silent no-op bulk update):
     *   - a bare primary key (`2`), which parse_str turns into ['2' => ''];
     *   - the `idPk[]=…` array form.
     * Both are accepted now; the bare-key case falls back to the parsed KEY,
     * but only when the value is empty (parse_str rewrites '.'/'[' in keys, so
     * a key is never trusted when a value is present).
     *
     * @param mixed $raw the raw (urlencoded) field value
     * @return string[] primary keys, in selection order, never empty strings
     */
    public static function bulkSelection($raw): array
    {
        $decoded = urldecode(trim((string) $raw));
        if ($decoded === '') {
            return [];
        }
        $parsed = [];
        parse_str($decoded, $parsed);

        $out = [];
        foreach ($parsed as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $one) {
                    if (! is_array($one) && (string) $one !== '') {
                        $out[] = (string) $one;
                    }
                }
                continue;
            }
            if ((string) $value !== '') {
                $out[] = (string) $value;
            } elseif ((string) $key !== '') {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * Must this request be refused because it reaches a WRITE over GET?
     *
     * Args-based counterpart of AuthyMiddleware::checkMutatingGet(), for callers
     * that hold the RouteHelper $args array rather than the PSR-7 request — i.e.
     * the emitted Service::getResponse() (the generated controller), which the
     * emitter guards as defence in depth behind the middleware. Same action
     * inventory (isMutatingAction) — nothing is re-derived here.
     *
     * There are NO exemptions: every mutating action is POST-only. The two
     * first-party GET escapes that existed here (generatepdf by top-level
     * window.open; opengdrive / stripecheckout / stripecharge by XHR-marked GET
     * fetch) were removed once the template client switched them to POST
     * (F3, review #13). An un-rebuilt project simply gets the 405 — the safe
     * direction — so no compatibility fallback is kept.
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

        return self::isMutatingAction((string) ($args['a'] ?? ($args['action'] ?? '')));
    }

    /**
     * PROJECT-DEFINED custom actions a cookie-auth GET may reach (I-5).
     *
     * `MUTATING_ACTIONS` is a fixed inventory of the case labels the EMITTER
     * writes. A wrapper that registers its own action in `$customActions`
     * (`approveInvoice`, `sendBatch`, `recalcTotals`, …) is dispatched from the
     * same `{a}` URL segment on the same GET-registered route, is invisible to
     * that inventory, and was therefore reachable by a cross-site
     * `<a href=".../Invoice/approveInvoice/42">` on the SameSite=Lax session
     * cookie — fail-OPEN for exactly the actions a project author adds by hand.
     *
     * The rule is now fail-CLOSED: an unknown custom action is treated as
     * mutating on a cookie-auth GET and refused with 405/"This action requires
     * POST". A custom action that really is a read opts out by listing its
     * ACTION NAME (the `$customActions` key, not the method name) here:
     *
     *     class InvoiceServiceWrapper extends InvoiceService
     *     {
     *         public $customActions = ['approveInvoice' => 'approve',
     *                                  'agingReport'   => 'aging'];
     *         protected array $readOnlyCustomActions = ['agingReport'];
     *     }
     *
     * Bearer/API callers are unaffected (no ambient cookie authority, and they
     * dispatch through getApiResponse()), and POST is never refused — so the
     * only change a project can see is a hand-written READ that was being
     * linked with an <a href>: name it here and it works again.
     *
     * @var string[] custom action names ($customActions keys), compared
     *               case-insensitively
     */
    protected array $readOnlyCustomActions = [];

    /** True when $action is declared read-only by this service. */
    public function isReadOnlyCustomAction(?string $action): bool
    {
        return self::actionIsDeclaredReadOnly($this->readOnlyCustomActions, $action);
    }

    /**
     * Read the opt-out list off ANY service object.
     *
     * The emitted `<T>Service` classes do NOT extend this class — they are
     * standalone and carry `$customActions` / `$readOnlyCustomActions` as plain
     * properties (goatcheese Classes/Service.php) — so the declaration cannot be
     * reached through inheritance. Read it wherever it is: the method when the
     * service does extend this base, otherwise the property (reflection, so a
     * protected declaration in a wrapper works too).
     *
     * @param object $service
     * @return string[]
     */
    public static function readOnlyCustomActionsOf($service): array
    {
        if (!is_object($service)) {
            return [];
        }
        try {
            $rc = new \ReflectionClass($service);
            if ($rc->hasProperty('readOnlyCustomActions')) {
                $prop = $rc->getProperty('readOnlyCustomActions');
                $prop->setAccessible(true);
                return (array) ($prop->isInitialized($service) ? $prop->getValue($service) : []);
            }
        } catch (\Throwable $e) {
            // fall through — an unreadable declaration means "no opt-out"
        }
        return [];
    }

    /** Case-insensitive membership test against an opt-out list. */
    private static function actionIsDeclaredReadOnly(array $list, ?string $action): bool
    {
        $action = strtolower(trim((string) $action));
        if ($action === '') {
            return false;
        }
        foreach ($list as $ro) {
            if (strtolower(trim((string) $ro)) === $action) {
                return true;
            }
        }
        return false;
    }

    /**
     * Should this custom action be refused? Same cookie-auth-GET test as
     * mutatingGetRefusal(), but the verdict for an action the emitter's
     * inventory does not know: refuse unless the service declares it read-only.
     *
     * Called from the emitted `default:` arm of getResponse(), where
     * `$this->customActions` is visible — the middleware cannot see it.
     *
     * @param object $service the emitted service ($this at the call site)
     * @param array  $args    RouteHelper::getArgs() output
     * @param mixed  $request optional PSR-7 request for the header reads
     */
    public static function customActionGetRefusal($service, array $args, $request = null): bool
    {
        $action = (string) ($args['a'] ?? ($args['action'] ?? ''));
        if (trim($action) === '') {
            return false;
        }
        if (self::actionIsDeclaredReadOnly(self::readOnlyCustomActionsOf($service), $action)) {
            return false;
        }
        // Reuse the cookie-auth-GET test verbatim by asking about an action name
        // the inventory is guaranteed to contain: everything mutatingGetRefusal()
        // checks before isMutatingAction() (method, api/bearer) is what we want,
        // and the verdict for an unknown custom action is "mutating".
        return self::mutatingGetRefusal(['method' => $args['method'] ?? '',
            'is_api' => $args['is_api'] ?? null, 'isApiCall' => $args['isApiCall'] ?? null,
            'a' => 'delete'], $request);
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
