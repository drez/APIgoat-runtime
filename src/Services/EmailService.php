<?php

/**
 * Service Class
 * Provide Response for the backend controler
 */
 
namespace ApiGoat\Services;

use ApiGoat\Services\Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Domains\Email\EmailSender;
use ApiGoat\Api\ApiResponse;

class EmailService extends Service
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
        $this->body = ['status' => 'failure', 'errors' => ['Unknown method'], 'messages' => []];

        switch ($this->args['method']) {

            case 'GET':
                // get status of email

                //$this->body = $this->getJson($this->args);
                break;
            case 'POST':
                // send email

                $EmailSender = new EmailSender($this->args);
                if (!$EmailSender->checkRequest()) {
                    $EmailSender->sendEmails();
                }
                $this->body = $EmailSender->getResponseBody();
                break;
        }

        $ApiResponse = new ApiResponse($this->args, $this->response, $this->body);
        return $ApiResponse->getResponse();
    }
}
