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
                // Self-service update of the signed-in user's OWN row — a strict
                // whitelist, never client-named columns. Currently: theme
                // (validated against the authy.theme ENUM valueSet, mirroring
                // MetaService::allowedThemes). Email/password stay on the
                // dedicated flows (Authy reset / per-project Account page).
                $theme = $this->args['data']['theme'] ?? null;
                if (is_string($theme) && $theme !== '') {
                    $allowed = \ApiGoat\Services\MetaService::allowedThemes();
                    if (!in_array($theme, $allowed, true)) {
                        $this->body = ['status' => 'failure', 'data' => null, 'errors' => ['Unknown theme'], 'messages' => null];
                        break;
                    }
                    $Authy = \App\AuthyQuery::create()->findPk($_SESSION[\_AUTH_VAR]->get('id'));
                    if ($Authy && method_exists($Authy, 'setTheme')) {
                        $Authy->setTheme($theme);
                        $Authy->save();
                        // Refresh the session cache so the next /_meta reflects it.
                        if (isset($_SESSION[\_AUTH_VAR]->sessVar)) {
                            $_SESSION[\_AUTH_VAR]->sessVar['Theme'] = $theme;
                        }
                        $this->body = ['status' => 'success', 'data' => ['theme' => $theme]];
                    } else {
                        $this->body = ['status' => 'failure', 'data' => null, 'errors' => ['Cannot save'], 'messages' => null];
                    }
                }
                break;
        }

        $ApiResponse = new ApiResponse($this->args, $this->response, $this->body);
        return $ApiResponse->getResponse();
    }
}
