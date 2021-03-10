<?php

/**
 * Service Class
 * Provide Response for the backend controler
 */

namespace ApiGoat\Services;

use ApiGoat\Services\Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ApiGoat\Api\ApiResponse;

class AccountService extends Service
{

    private $config = [];

    public function __construct(Request $request, Response $response, $args)
    {
        parent::__construct($request, $response, $args);
    }
    /**
     * Get the proper api response
     * @return array
     */
    public function getApiResponse()
    {
        $this->body = ['status' => 'failure', 'data' => null, 'errors' => ['Unknown method'], 'messages' => null];

        switch ($this->args['method']) {

            case 'GET':
                // my account info
                $Authy = \App\AuthyQuery::create()
                    ->findPk($_SESSION[\_AUTH_VAR]->get('id'));
                if ($Authy) {
                    $Authy = $Authy->toArray(\BasePeer::TYPE_FIELDNAME);
                    $systemColumns = ['validation_key', 'passwd_hash', 'passwd', 'root', 'deactivate', 'rights_all', 'rights_owner', 'rights_group', 'onglet'];
                    foreach ($Authy as $column => $value) {
                        if (!in_array($column, $systemColumns)) {
                            $data[$column] = $value;
                        }
                    }
                    $this->body = ['status' => 'success', 'data' => $data];
                } else {
                    $this->body = ['status' => 'failure', 'messages' => ["Something is not right."]];
                }

                break;
            case 'POST':
            case 'PATCH':
                // update Email /password
                break;
        }

        $ApiResponse = new ApiResponse($this->args, $this->response, $this->body);
        return $ApiResponse->getResponse();
    }
}
