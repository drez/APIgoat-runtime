<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace ApiGoat\Api;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Description of ApiResponse
 *
 * @author sysadmin
 */
class ApiResponse
{

    private $status = 'failure';
    private $body = [
        'status' => 'failure',
        'data' => [],
        'messages' => [],
        'errors' => []
    ];

    /**
     * [ids, action, debug, messages ]
     */

    private $statusCode = [
        'success' =>
        [
            'GET' => 200,
            'POST' => 201,    //Created
            'PUT' => 200,
            'PATCH' => 200,
            'DELETE' => 200,
            'AUTH' => 200,
        ],
        'failure' => [
            'Auth-Missing' => 401,    // Unauthorized, login required
            'Permissions' => 403,     // Forbidden, permission required
            'Unknown' => 400,         // Bad Request
            'Method-Not-Allowed' => 405 // Method Not Allowed
        ]
    ];

    /**
     * Set the status and write the body on the Response
     * WATCH OUT: Builder API uses $this-request as $args when calling the class
     *
     * @param array $args
     * @param Response $response
     * @param array $body
     */
    public function __construct(array $args, $response, array $body)
    {
        $this->args = $args;
        $this->response = ($response instanceof ResponseInterface) ? $response : new Response();
        $this->setBody($body);
        $this->setStatus();
    }

    public function setStatus($status = null)
    {
        if ($status) {
            $this->status = $status;
        } else {
            if ($this->body['status'] == 'success') {
                if ($this->args['action'] == 'list') {
                    $this->args['method'] = 'GET';
                }
                $this->status = $this->statusCode[$this->body['status']][$this->args['method']];
            } else {
                $this->status = $this->statusCode[$this->body['status']]['Unknown'];
            }

            if (empty($this->status)) {
                $this->status = 400;
            }
        }
        return $this;
    }

    private function setBody($body)
    {
        if (is_array($body)) {
            $this->body = $body;
        }
    }

    public function getQueryParam()
    {
        return http_build_query($this->body);
    }

    public function getResponse()
    {
        $this->response->getBody()->write(json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $this->response->withStatus($this->status)->withHeader('Content-Type', 'application/json');
    }
}
