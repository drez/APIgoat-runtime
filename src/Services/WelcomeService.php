<?php

namespace ApiGoat\Services;

//require \_INSTALL_PATH."/src/App/Domains/Dashboard/View.php";

use ApiGoat\Services\Service;
use ApiGoat\Views\WelcomeView;
use App\Domains\Dashboard\View as DashboardView;

class WelcomeService extends Service
{

    public $args;
    private $View;
    private $body;

    public function __construct($request, $response, $args)
    {
        parent::__construct($request, $response, $args);

        if(!class_exists('\App\Domains\Dashboard\View')){
            $this->View = new WelcomeView($request, $args);
        } else {
            $this->View = new DashboardView($request, $args);
            if(!$this->View->override){
                $this->View = new WelcomeView($request, $args);
            }
        }
        
    }

    /**
     * Get the proper response
     * @return string
     */
    public function getResponse()
    {
        $this->body = ['html' => "Unknown method"];
        if (!isset($this->args['a'])) {
            $this->body = $this->View->dashboard();
        }

        if (isset($this->args['ui'])) {
            return $this->BuilderLayout->renderXHR($this->body);
        } else {
            return $this->BuilderLayout->render($this->body);
        }
    }
}
