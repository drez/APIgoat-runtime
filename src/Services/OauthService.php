<?php

/**
 * Service Class
 * Provide Response for the backend controler
 */
//https://goat.local/p/goatcheese/oAuth/facebook/int_callback?code=AQA7kFxguKEtIxdmzqZDA8Pau3S6Sibgm-nMBydLJrOhxI49jgYR2IGIY-u08IEjnEK24DDSfHrs81pTAvDj-u7Wo0ZoL1X370II9JfEryIlw_WnHYpokLa6LGQjNCgwmtMwT4Ths4KVRwU0qMn2kaG2NQ6ROD-mwlzwTmG2bnvPAQwA1znZvy2mu72m4VaJ5ob3GLduD-CWuUh-Ga8ZcLEYxNF2j9gKjyWuU9U0Flg_r8PYe261dmGpjwcN05k-zz6igAhJ5rWxl--JwRRMwsWRe1kGX5A3bwQ4SVNn0HB7qibhzdJz10JSSIMMDOgXeNE

namespace ApiGoat\Services;

use ApiGoat\Services\Service;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Selective\Config\Configuration;
use Opauth;
use ApiGoat\Handlers\PropelErrorHandler;
use ApiGoat\Api\ApiResponse;

class OauthService extends Service
{

    private $config = [];
    private $jwt_config = [];
    private $isApi = false;

    public function __construct(Request $request, Response $response, $args)
    {
        parent::__construct($request, $response, $args);
        $Configuration = new Configuration(require _BASE_DIR . 'config/settings.php');
        $this->config = $Configuration->getArray('oauth');
        $this->jwt_config = $Configuration->getArray('jwt_middleware');
    }
    /**
     * Get the proper response
     * @return array
     */
    public function getResponse()
    {
        if ($this->args['action'] == 'callback') {
            if ($this->args['data']['error']) {
                return $this->args['data']['error_description'];
            } else {
                if ($this->args['data']['opauth']) {
                    $data = json_decode(base64_decode($this->args['data']['opauth']), true);
                }
            }

            if (is_array($data)) {
                $result = $this->process($data);

                if ($result['errors']) {
                    $errors = '';
                    foreach ($result['errors'] as $error) {
                        $errors .= div($error);
                    }

                    $result['errors'] = $this->BuilderLayout->decorate(
                        $errors,
                        [
                            'type' => 'warning',
                            'content-style' => 'text-align:center',
                            'bottom' => div(href(_("Back to login"), _SITE_URL . 'Authy/login', "class='button-link-blue'"), '', "")
                        ]
                    );
                }
            } else {
                $result['errors'] = $this->BuilderLayout->decorate(
                    "Something went wrong",
                    [
                        'type' => 'warning',
                        'content-style' => 'text-align:center',
                        'bottom' => div(href(_("Back to login"), _SITE_URL . 'Authy/login', "class='button-link-blue'"), '', "")
                    ]
                );
            }
        } else {
            $Opauth = new Opauth($this->config);
        }
        return $result;
    }

    /**
     * Get the proper api response
     * @return array
     */
    public function getApiResponse()
    {
        $this->isApi = true;
        $result['status'] = 'failure';
        if ($this->args['action'] == 'callback') {
            if ($this->args['error']) {
                $result['errors'][] = $this->args['error_description'];
            } else {
                if ($this->args['opauth']) {
                    $data = json_decode(base64_decode($this->args['opauth']), true);
                }
            }

            if (is_array($data)) {
                $result = $this->process($data);
                $result['status'] = 'success';
            } else {
                $result['errors'][] = "Something went wrong";
            }

            $ApiResponse = new ApiResponse($this->args, $this->response, $result);
            $params = $ApiResponse->getQueryParam();
            return $this->response->withHeader('Location', $this->config['frontend_callback_url'] . "?" . $params)->withStatus(301);
        } else {
            $this->config['path'] = 'api/v1/' . $this->config['path'];
            $this->config['Strategy'] = $this->config['StrategyApi'];
            $this->config['callback_url'] = str_replace('oauth/callback', 'api/v1/oauth/callback', $this->config['callback_url']);
            $Opauth = new Opauth($this->config);
        }
    }

    private function process(array $response)
    {
        if ($response['code']) {
            switch ($response['code']) {
                case 'access_token_error':
                    $return['errors'][] = $response['message'];
                    break;
            }
        } elseif ($response['error']) {
            $return['errors'][] = $response['error'];
            $return['errors'][] = $response['error_description'];
        } else {
            $return = $this->authenticate($response['auth']);
        }

        return $return;
    }

    private function authenticate(array $auth)
    {

        if (!is_array($auth) || !is_array($auth['info'])) {
            return ['errors' => ["General: decryption error."]];
        } elseif (empty($auth['info']['email'])) {
            return ['errors' => ["General: cant access email."]];
        }

        $Authy = \App\AuthyQuery::create()
            ->filterByOaProvider(strtolower($auth['provider']))
            ->filterByOaClientId($auth['uid'])
            ->findOne();

        // User exists
        if ($Authy) {
            if ($this->isApi) {
                $AuthyService = new \App\AuthyServiceWrapper($this->request, $this->response, $this->args);
                $jwt = $AuthyService->getToken($Authy, $this->jwt_config['secret']);
                return ['jwt' => $jwt];
            } else {
                $AuthyService = new \App\AuthyServiceWrapper($this->request, $this->response, $this->args);
                $AuthyService->setSession($Authy);
            }

            return false;
        } elseif ($this->config['auto_register'] === true) {
            $Authy = \App\AuthyQuery::create()
                ->filterByEmail($auth['info']['email'])
                ->findOneOrCreate();

            if (!$Authy->isNew()) {
                if ($Authy->getOaProvider() == strtolower($auth['provider'])) {
                    if ($this->isApi) {
                        $AuthyService = new \App\AuthyServiceWrapper($this->request, $this->response, $this->args);
                        $jwt = $AuthyService->getToken($Authy, $this->jwt_config['secret']);
                        return ['jwt' => $jwt, 'id' => $Authy->getPrimaryKey(), 'action' => 'login'];
                    } else {
                        $AuthyService = new \App\AuthyServiceWrapper($this->request, $this->response, $this->args);
                        $AuthyService->setSession($Authy);
                    }
                    return false;
                } else {
                    return ['errors' => ["The email is already in use with a different provider."]];
                }
            } elseif ($Authy->isNew()) {
                $Authy->setOaProvider(strtolower($auth['provider']));
                $Authy->setOaClientId($auth['info']['uid']);
                $Authy->setOaSecret($auth['credentials']['token']);
                $Authy->setFullname($auth['info']['nickname']);
                $Authy->setPasswdHash(createRandomKey(32));
                $Authy->setIdAuthyGroup(1);
                $Authy->setPlan('Free');
                $Authy->setIdCreation(1);
                $Authy->setIdModification(1);
                $Authy->setDeactivate('No');

                if ($Authy->validate()) {
                    $Authy->save();
                    if ($this->isApi) {
                        $AuthyService = new \App\AuthyServiceWrapper($this->request, $this->response, $this->args);
                        $jwt = $AuthyService->getToken($Authy, $this->jwt_config['secret']);
                        return ['jwt' => $jwt, 'id' => $Authy->getPrimaryKey(), 'action' => 'register'];
                    } else {
                        $AuthyService = new \App\AuthyServiceWrapper($this->request, $this->response, $this->args);
                        $AuthyService->setSession($Authy);
                    }
                    return false;
                } else {
                    $PropelErrorHandler = new PropelErrorHandler($Authy);
                    $error = $PropelErrorHandler->getValidationErrors();
                    return ['errors' => ["Autoregister: Cannot add new user from " . $auth['provider'] . ": " . $error['txt']]];
                }
            } else {
                return ['errors' => ["Autoregister: Email is already in use."]];
            }
        } else {
            return ['errors' => ["You do not have a linked account with this provider: " . $auth['provider']]];
        }
    }
}
