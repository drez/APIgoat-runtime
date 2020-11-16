<?php
namespace ApiGoat\Utility;
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

use Psr\Http\Message\ResponseInterface;
/**
 * Description of BuilderResponse
 *
 * @author sysadmin
 */
class BuilderResponse extends ResponseInterface{
    
    private $html;
    private $js;
    private $css;
    private $onReadyJs;
    
    public function setHtml($html){
        $this->html = $html;
    }
    
    public function setJs($js){
        $this->js = $js;
    }
    
    public function setCss($css){
        $this->css = $css;
    }
    
    public function setOnReadyJs($onReadyJs){
        $this->onReadyJs = $onReadyJs;
    }
}
