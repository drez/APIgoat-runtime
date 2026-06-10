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

                    // Reset keys embed their mint time as an 8-hex-char prefix
                    // (see EmailSender::getEmailContent in the template).
                    // Expire after 24h; legacy keys without the prefix are
                    // treated as expired and cleared.
                    if ($Authy) {
                        $key      = (string) $this->args['i'];
                        $mintedAt = (strlen($key) >= 8 && ctype_xdigit(substr($key, 0, 8))) ? hexdec(substr($key, 0, 8)) : 0;
                        if ($mintedAt < time() - 86400 || $mintedAt > time() + 3600) {
                            $Authy->setValidationKey('');
                            $Authy->save();
                            $Authy = null;
                        }
                    }

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
