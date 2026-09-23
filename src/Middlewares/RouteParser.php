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
    private $headers;


    public function __construct()
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->method != 'OPTIONS') {
            $this->request = $request;
            $this->method = $request->getMethod();
            $this->args['method'] = $this->method;
            $this->headers = $request->getHeaders();

            $this->decodePath();
            $this->getArgs();
            $this->setAction();
            $request = $request->withAttribute('parsed_args', $this->args);
        }
        return $handler->handle($request);
    }

    private function getContentType()
    {
        $contentTypes = [
            'application/xml',
            'text/xml',
            'application/x-www-form-urlencoded',
            'application/json'
        ];

        if (is_array($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = $this->headers['Content-Type'][0];
        }

        if (!empty($this->headers['Content-Type']) && !in_array($this->headers['Content-Type'], $contentTypes)) {
            error_log(sprintf(
                'RouteParser: unrecognized Content-Type "%s" on %s',
                $this->headers['Content-Type'],
                $this->request->getUri()->getPath()
            ));
        }

        if ($this->headers['Content-Type']) {
            return $this->headers['Content-Type'];
        }
    }

    private function setAction()
    {

        if (isset($this->args['action']) && $this->args['action'] != 'auth') {
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
                    $body = ($this->args['method'] == 'GET') ? $this->args['query'] : $this->args['data'];
                    $this->args['data'] = $body;

                    if (($body['query'] && count($body) == 1) || ($body['query'] && count($body) == 2 && isset($body['debug'])) || isset($body['ms'])) {
                        $this->args['action'] = 'list';
                    } elseif ($this->args['method'] == 'POST' || $this->args['method'] == 'PATCH') {
                        if (!empty($this->args['id']) || $this->args['method'] == 'PATCH' || $body['query']) {
                            $this->args['action'] = 'update';
                        } else {
                            $this->args['action'] = 'create';
                        }
                    } elseif (empty($this->args['data'])) {
                        $this->args['action'] = 'list';
                    }
                } else {
                    $this->args['action'] = 'list';
                }
            } elseif ($this->args['action'] == 'update') {
                // `update` without an id in the URL is a create ('a') — unless
                // the GUI body names the record, exactly as the emitted
                // Service::saveUpdate() decides (see guiBodyNamesRecord()).
                if (empty($this->args['id']) && !self::guiBodyNamesRecord($this->args)) {
                    $this->args['action'] = 'create';
                }
            }
        }

        if (empty($this->args['action'])) {
            $this->args['action'] = 'list';
        }
    }

    /**
     * Does a GUI POST to {Model}/update (no id in the URL) name an EXISTING
     * record? Mirrors the emitted Service::saveUpdate() branch signal:
     *
     *     parse_str($request['d'], $data);
     *     $data['i'] = $data['<FirstPkPhpName>'] ?: $request['i'];
     *     if (!empty($data['i'])) { ## Save (loadPkScoped 'w') } else { ## Create ('a') }
     *
     * where $request['i'] falls back to the body 'i'. The client
     * (template screens.js) posts every save — create and edit alike — to
     * {Model}/update with the form serialized into 'd' and the PK as a hidden
     * <FirstPkPhpName> field, so treating every id-less update as a create made
     * AuthyMiddleware demand 'a' for a plain edit: a 'rw' user got 403
     * [Model, a] saving an existing record. Using the SAME signal as the
     * Service means the middleware right and the Service branch cannot
     * disagree; the Service still enforces 'w' on the named row
     * (loadPkScoped) and 'a' on its create branch. Composite PKs: the Service
     * reads the FIRST PK column only, and so does this.
     *
     * The JSON API (is_api) is left alone: Api::setJson() makes its own
     * create/update call ('w' for a body PK) and an 'update' action would
     * switch it onto the QueryBuilder path.
     */
    public static function guiBodyNamesRecord(array $args, ?string $pkPhpName = null): bool
    {
        if (!empty($args['is_api'])) {
            return false;
        }
        $data = is_array($args['data'] ?? null) ? $args['data'] : [];
        if (!empty($data['i'])) {
            return true;
        }
        if (!isset($data['d']) || !is_string($data['d']) || $data['d'] === '') {
            return false;
        }
        $pk = $pkPhpName ?? self::firstPkPhpName((string) ($args['model'] ?? ''));
        if ($pk === null || $pk === '') {
            return false;
        }
        parse_str($data['d'], $d);
        return !empty($d[$pk]);
    }

    /**
     * PhpName of the model's first primary-key column (what the emitter's
     * $this->pkName is: getFirstPrimaryKeyColumn()->getPhpName()), or null
     * when the route model has no Propel peer — the caller then keeps the
     * old id-less-update-is-a-create rule (fails toward requiring 'a').
     */
    public static function firstPkPhpName(string $model): ?string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $model)) {
            return null;
        }
        $peer = '\\App\\' . $model . 'Peer';
        try {
            if (class_exists($peer) && method_exists($peer, 'getTableMap')) {
                foreach ($peer::getTableMap()->getPrimaryKeys() as $col) {
                    return (string) $col->getPhpName();
                }
            }
        } catch (\Throwable $e) {
            error_log('RouteParser: no PK map for ' . $model . ': ' . $e->getMessage());
        }
        return null;
    }

    private function decodePath()
    {
        $data = [];
        $path = preg_replace('*' . _SUB_DIR_URL . '*', '', $this->request->getUri()->getPath(), 1);

        # API call
        $data['is_api'] = false;
        if (preg_match('#^/?api/v[0-9]+/#', $path)) {
            $path = preg_replace('#^/?api/v[0-9]+/#', '', $path, 1);
            $data['is_api'] = true;
        }

        $path = trim($path, "/");
        // getPath() is the RAW encoded URI: a composite-PK id segment like
        // %5B49%2C%222026-07-28%22%5D must be decoded or json_decode() on the
        // id nulls out downstream (blank edit forms). Decode per segment,
        // AFTER the explode, so an encoded slash can never change the split.
        $pathPart = array_map('rawurldecode', explode('/', $path));

        $data['id'] = (isset($pathPart[2])) ? $pathPart[2] : '';
        if (!empty($pathPart[0])) {
            $data['model'] = $pathPart[0];
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
        $this->args['data'] = $this->request->getQueryParams();
        //$this->args['body'] = ($this->request->getParsedBody()) ? (array)$this->request->getParsedBody() : null;
    }

    /**
     * get passed arguments for the POST method
     * @return array
     */
    private function getPOSTArgs()
    {
        $raw = file_get_contents('php://input');
        $this->args['raw'] = $raw;

        if (is_array($this->request->getParsedBody())) {
            $this->args['data'] = $this->request->getParsedBody();
        } else {
            $this->args['data'] = $this->parseBody($raw);
        }

    }

    private function parseBody($raw)
    {
        $contentType = $this->getContentType();
        if (strstr($contentType, 'json')) {
            return json_decode($raw, true);
        }
        if (strstr($contentType, 'form-urlencoded')) {
            parse_str($raw, $data);
            return $data;
        }
        return $raw;
    }
}