<?php

namespace ApiGoat\Middlewares;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
class RouteParser implements MiddlewareInterface
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
    private $args;
    /**
     * Request object
     * @var Request 
     */
    private $request;


    public function __construct()
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->request = $request;
        $this->method = $request->getMethod();
        $this->args['method'] = $this->method;

        $this->decodePath();
        $this->getArgs();
        $this->setAction();
        $request = $request->withAttribute('parsed_args', $this->args);

        return $handler->handle($request);
    }

    private function setAction()
    {

        if ($this->args['action'] != 'auth') {
            if ($this->args['action'] == 'edit' && $this->args['method'] != 'GET') {
                if (!empty($this->args['id']) || !empty($this->args['Id' . $this->args['model']])) {
                    $this->args['action'] = 'edit';
                } else {
                    $this->args['action'] = 'create';
                }
            }

            if ($this->args['method'] == 'DELETE') {
                $this->args['action'] = 'delete';
            }

            if (empty($this->args['action'])) {
                if ($this->args['method'] == 'GET' || $this->args['method'] == 'POST' || $this->args['method'] == 'PATCH') {
                    // check if its a QueryBuilder request
                    $body = ($this->args['method'] == 'GET') ? $this->args['query'] : $this->args['body'];
                    $this->args['body'] = $this->args['query'];
                    if (($body['query'] && count($body) == 1) || ($body['query'] && count($body) == 2 && isset($body['debug']))) {
                        $this->args['action'] = 'list';
                    } elseif ($this->args['method'] == 'POST' || $this->args['method'] == 'PATCH') {
                        if (!empty($this->args['id']) || $this->args['method'] == 'PATCH') {
                            $this->args['action'] = 'update';
                        } else {
                            $this->args['action'] = 'create';
                        }
                    }
                } else {
                    $this->args['action'] = 'list';
                }
            } elseif ($this->args['action'] == 'update') {
                if (empty($this->args['id'])) {
                    $this->args['action'] = 'create';
                }
            }
        }
    }

    private function decodePath()
    {
        $data = [];
        $path =  preg_replace('*' . _SUB_DIR . '*', '', $this->request->getUri()->getPath(), 1);

        # API call
        if (strstr($path, 'api/')) {
            $path = preg_replace('/api\/v[0-9]\//', '', $path, 1);
            $data['is_api'] = true;
        }

        $path = trim($path, "/");
        $pathPart = explode('/', $path);

        $data['id'] = (isset($pathPart[2])) ? $pathPart[2] : '';
        if (!empty($pathPart[0])) {
            $data['model'] =  $pathPart[0];
            if (!empty($pathPart[1])) {
                if (\is_numeric($pathPart[1])) {
                    $data['action'] = '';
                    $data['id'] = $pathPart[1];
                } else {
                    $data['action'] = $pathPart[1];
                }
            }
        } else {
            $data['model'] = '';
        }

        $data['route'] = (empty($path) ? "/" : $path);
        $this->args = array_merge($this->args, $data);
    }

    /**
     * Return a array of combined route parameters, and passed arguments
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
            $this->$getArgsFct();
        } else {
            throw new \Exception('Method not implemented in RouteParser:' . $this->method);
        }
    }

    private function getDELETEArgs()
    {
        $this->getPOSTArgs();
    }

    private function getOPTIONSArgs()
    {
    }

    private function getPATCHArgs()
    {
        $this->getPOSTArgs();
    }

    /**
     * get passed arguments for the GET method
     * @return array
     */
    private function getGETArgs()
    {
        $this->args['query'] = $this->request->getQueryParams();
        $this->args['body'] = ($this->request->getParsedBody()) ? (array)$this->request->getParsedBody() : null;
    }

    /**
     * get passed arguments for the POST method
     * @return array
     */
    private function getPOSTArgs()
    {
        $raw = file_get_contents('php://input');
        $this->args['body'] = ($this->request->getParsedBody()) ? (array)$this->request->getParsedBody() : null;
    }
}
