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

class PasswordService extends Service
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

            case 'POST':
            case 'PATCH':
                // send email
                if ($this->args['i'] && $this->args['data']['passwd_hash']) {
                    $Authy = \App\AuthyQuery::create()->filterByValidationKey($this->args['i'])->findOne();
                    if ($Authy) {
                        $Authy->setPasswdHash($this->args['data']['passwd_hash']);
                        $Authy->setValidationKey('');
                        if ($Authy->validate()) {
                            $Authy->save();
                            $this->body = ['status' => 'success', 'messages' => ["Password updated"]];
                        } else {
                            $this->body = ['status' => 'failure', 'messages' => ["Something went horribly wrong."]];
                        }
                    } else {
                        $this->body = ['status' => 'failure', 'messages' => ["The key is expired or invalid."]];
                    }
                } else {
                    $this->body = ['status' => 'failure', 'messages' => ["Something is missing."]];
                }

                break;
        }

        $ApiResponse = new ApiResponse($this->args, $this->response, $this->body);
        return $ApiResponse->getResponse();
    }
}
