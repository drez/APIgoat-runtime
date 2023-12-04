<?php

namespace ApiGoat\Services;

use ApiGoat\Services\Service;
use ApiGoat\Views\WelcomeView;

class WelcomeService extends Service
{

    private $args;
    private $View;
    private $body;

    public function __construct($request, $response, $args)
    {
        parent::__construct($request, $response, $args);

        $this->View = new WelcomeView($request, $args);
    }

    /**
     * Get the proper response
     * @return html
     */
    public function getResponse()
    {
        $this->body = ['html' => "Unknown method"];
        if(!isset($this->args['a'])){
           $this->body = $this->View->dashboard();
        }
        
        if (isset($this->args['ui'])) {
            return $this->BuilderLayout->renderXHR($this->body);
        } else {
            return $this->BuilderLayout->render($this->body);
        }
    }
}
