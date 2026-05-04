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
