<?php

namespace ApiGoat\Routes;

use Psr\Http\Message\ServerRequestInterface as Request;
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
     * @var Route
     */
    private $route;
    /**
     * Combined arguments and parameters set for the ApiGoat backend
     * @var array
     */
    private $args = [
        // original route action from the routeProvider, 'action will be overwritten by RouteParser'
        'a' => '',
        'i' => ''
    ];
    /**
     * Request object
     * @var Request 
     */
    private $request;
    private $baseRouteName;


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
        $legacyVars = ['ui', 'pui', 'ms', 'order', 'pg', 'd', 'je', 'jet', 'dialog', 'modelName', 'destUi', 'pc', 'tp'];
        foreach ($legacyVars as $legacyVar) {
            if (isset($this->args['data'][$legacyVar])) {
                $this->args[$legacyVar] = $this->args['data'][$legacyVar];
            }
        }
    }

    /**
     * Set one named route parameters
     * @return array
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
        $this->args = array_merge($this->args, $get);

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
        $this->args = array_merge($this->args, $post);

        return $this->args;
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
        $params = explode('/', $this->args['params']);
        if (!empty($params[0])) {
            $this->args['i'] = $params[0];
        }
        $this->args['p'] = $this->getRouteName();

        $post = ($this->request->getParsedBody()) ? $this->request->getParsedBody() : [];
        $this->args['bodyArgs'] = count($post);
        $this->args = array_merge($this->args, $post);

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
        } else {
            throw new \Exception('Service class (' . $ServiceClass . ') not found');
        }
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