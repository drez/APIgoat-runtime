<?php

namespace ApiGoat\Routes;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;


/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Help with building arguments for each method and find teh right services for the request
 *
 * @author sysadmin
 */
class RouteHelper
{


    /**
     *
     * @var string 
     */
    private $routeName;
    /**
     *
     * @var string
     */
    private $method;
    /**
     * Route object
     * @var RouteInterface
     */
    private $route;
    /**
     * Combined arguments and parameters set for the ApiGoat backend
     * @var array
     */
    private $args = [
        // original route action from the routeProvider, 'action will be overwritten by RouteParser'
        'a' => '',
        'i' => '',
        'act' => '',
        'ms' => '',
        'd' => '',
        'v' => '',
        'nomem' => '',
        'who' => '',
        'h' => '',
        'data' => [],
        'method' => [],
        'rbac_public' => [],
    ];
    /**
     * Request object
     * @var Request 
     */
    private $request;
    private $baseRouteName;
    /**
     * Snapshot of the route-layer's TRUSTED args (method + action) captured
     * immediately BEFORE the user query/body params are array_merge()d in. At
     * that moment $this->args['method'] holds the route's override (e.g. 'AUTH'
     * set via setArgs()) or, for a normal route, the constructor's HTTP method;
     * and $this->args['a'] holds the route/path-provided action. reassertTrustedArgs()
     * restores these so a client-supplied ?method= / ?a= can never override them.
     * @var array
     */
    private $preMergeTrusted = [];
    /**
     * True once a route explicitly pins its action via setArgs('a', …) — only
     * then is 'a' reasserted after the user-arg merge. Routes that read 'a' from
     * the query (GuiManager?a=alive, generic admin actions) leave it false so the
     * merged query value stands. 'method' is always reasserted (no route reads it
     * from the query); only 'a' is legitimately query-driven for some routes.
     * @var bool
     */
    private $aRouteLocked = false;


    public function __construct(Request $request, $args)
    {
        $this->request = $request;
        $routeContext = RouteContext::fromRequest($request);
        $this->route = $routeContext->getRoute();
        $this->method = $request->getMethod();
        $this->args = $request->getAttribute('parsed_args');
        $this->args['normalized_query'] = $request->getAttribute('normalized_query');
        //$this->args['action'] = $this->args['action'];

        //legacy
        $this->args['a'] = isset($args['a'])?$args['a']:null;
        $this->setLegacyVarFromBody();
        $this->args['i'] = (isset($this->args['id'])) ? $this->args['id'] : $this->args['i'];
        if (empty($this->args['i']) && isset($this->args['data']['i'])) {
            $this->args['i'] = $this->args['data']['i'];
        }

        $this->args['method'] = $this->method;
        $this->args['rbac_public'] = $request->getAttribute('rbac_public');
        $this->baseRouteName = $this->route->getName();
    }

    private function setLegacyVarFromBody()
    {
        $legacyVars = ['ui', 'pui', 'ms', 'order', 'pg', 'dpath', 'd', 'je', 'jet', 'dialog', 'modelName', 'destUi', 'pc', 'tp'];
        foreach ($legacyVars as $legacyVar) {
            if (isset($this->args['data'][$legacyVar])) {
                $this->args[$legacyVar] = $this->args['data'][$legacyVar];
            }
        }
    }

    /**
     * Set one named route parameters
     * @return void
     */
    public function setArgs($name, $value)
    {
        $this->args[$name] = $value;
    }

    /**
     * Return a array of combined route parameters, and passed arguments
     * @return array
     */
    public function getArgs()
    {
        $getArgsFct = 'get' . $this->method . 'Args';
        if (method_exists($this, $getArgsFct)) {
            return $this->$getArgsFct();
        } else {
            throw new \Exception('Method not implemented in RouteHelper:' . $this->method);
        }
    }

    /**
     *  Check if its an API call and return the final parsed route name
     * @return string
     */
    private function getRouteName()
    {
        if (strstr($this->baseRouteName, 'api/')) {
            #$this->routeName = str_replace('api/', '', $this->route->getName());
            $this->routeName = preg_replace('/api\//', '', $this->baseRouteName, 1);
            $this->args['isApiCall'] = true;
        } else {
            $this->routeName = $this->route->getName();
        }

        $this->args['routeName'] = $this->routeName;
        $this->args['route'] = $this->route->getName();

        # if not route name, get the name from the uri
        if (empty($this->routeName)) {
            $path = preg_replace('/' . _SUB_DIR_URL . '/', '', $this->request->getUri()->getPath(), 1);
            throw new \Exception('Route name empty: ' . $path);
        }

        $this->args['p'] = $this->routeName;

        return $this->routeName;
    }

    /**
     * get passed arguments for the GET method
     * @return array
     */
    private function getGETArgs()
    {
        if (isset($this->args['params'])) {
            $params = explode('/', $this->args['params']);
            if (!empty($params[0])) {
                $this->args['i'] = $params[0];
            }
        }

        $this->args['p'] = $this->getRouteName();

        $get = $this->request->getQueryParams();
        $this->args['queryArgs'] = count($get);
        $this->snapshotTrustedArgs();
        $this->args = array_merge($this->args, $get);
        $this->reassertTrustedArgs();

        return $this->args;
    }

    private function getDELETEArgs()
    {
        $params = explode('/', $this->args['params']);
        if (!empty($params[0])) {
            $this->args['i'] = $params[0];
        }
        $this->args['p'] = $this->getRouteName();

        $post = ($this->request->getParsedBody()) ? $this->request->getParsedBody() : [];
        $this->args['bodyArgs'] = count($post);
        $this->snapshotTrustedArgs();
        $this->args = array_merge($this->args, $post);
        $this->reassertTrustedArgs();

        return $this->args;
    }

    /**
     * Snapshot the route-layer's trusted method + action BEFORE the user
     * query/body array_merge, so reassertTrustedArgs() can restore them.
     * MUST be called immediately before every merge of user-controlled params.
     * @return void
     */
    private function snapshotTrustedArgs()
    {
        $this->preMergeTrusted = [
            'method' => isset($this->args['method']) ? $this->args['method'] : $this->method,
            'a'      => array_key_exists('a', $this->args) ? $this->args['a'] : null,
        ];
    }

    /**
     * Restore the middleware/route-derived args that the array_merge of raw
     * user query/body params could otherwise clobber. SECURITY: a client could
     * send ?rbac_public=passed to skip Api::getJson()/getOne()'s read-rights
     * gate, or method=POST / a=list to steer service dispatch into a write or
     * generic-read branch — the PRE-MERGE trusted snapshot and resolved route
     * name always win. method/a come from the snapshot (the route's trusted
     * override, or a normal route's HTTP method + path-derived action) — NOT
     * from the raw HTTP method, and never left user-overridable. This preserves
     * the original security intent (a user's ?method=/?a= is discarded) without
     * destroying the route layer's trusted setArgs('method','AUTH') override.
     * @return void
     */
    private function reassertTrustedArgs()
    {
        $this->args['method']      = array_key_exists('method', $this->preMergeTrusted)
            ? $this->preMergeTrusted['method'] : $this->method;
        // 'a' is reasserted ONLY when the route provided one before the merge —
        // set either via setArgs('a', …) (AUTH routes) or the path {a} placeholder
        // (generic admin Model/{a} actions). A route that leaves 'a' entirely to
        // the query (GuiManager?a=alive) has an EMPTY pre-merge 'a', so its query
        // value is left intact (reasserting would blank ?a=alive and break the
        // admin GUI/keepalive). Injection protection still holds: the AUTH dispatch
        // gadget ($this->{a}()) only runs on routes that pin a non-empty 'a'.
        $preA = array_key_exists('a', $this->preMergeTrusted) ? $this->preMergeTrusted['a'] : null;
        if ($preA !== null && $preA !== '') {
            $this->args['a'] = $preA;
        }
        $this->args['rbac_public'] = $this->request->getAttribute('rbac_public');
        $this->args['routeName']   = $this->routeName;
        $this->args['route']       = $this->route->getName();
        $this->args['p']           = $this->routeName;
        $this->args['isApiCall']   = (bool) strstr($this->baseRouteName, 'api/');
    }

    private function getOPTIONSArgs()
    {
    }

    /**
     * get passed arguments for the POST method
     * @return array
     */
    private function getPOSTArgs()
    {
        if (isset ($this->args['params'])) {
            $params = explode('/', $this->args['params']);
            if (!empty($params[0])) {
                $this->args['i'] = $params[0];
            }
        }
        
        $this->args['p'] = $this->getRouteName();

        $post = ($this->request->getParsedBody()) ? $this->request->getParsedBody() : [];
        $this->args['bodyArgs'] = count($post);
        $this->snapshotTrustedArgs();
        $this->args = array_merge($this->args, $post);
        $this->reassertTrustedArgs();

        return $this->args;
    }

    /**
     * return a configured Service object
     * @return object Service 
     */
    public function getService($response)
    {
        //$this->getArgs();
        $this->getRouteName();
        $ServiceClass = '\\App\\' . $this->routeName . 'ServiceWrapper';
        if (class_exists($ServiceClass)) {
            return new $ServiceClass($this->request, $response, $this->args);
        }
        // Fall back to the bare <X>Service when no wrapper class is emitted.
        $BareServiceClass = '\\App\\' . $this->routeName . 'Service';
        if (class_exists($BareServiceClass)) {
            return new $BareServiceClass($this->request, $response, $this->args);
        }
        throw new \Exception('Service class (' . $ServiceClass . ' or ' . $BareServiceClass . ') not found');
    }

    /**
     * get passed arguments for the PATCH method
     * @return array
     */
    private function getPATCHArgs()
    {
        return $this->getPOSTArgs();
    }
}