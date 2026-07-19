<?php
namespace ApiGoat\Utility;
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of BuilderResponse
 *
 * Plain builder DTO (setHtml/setJs/setCss/setOnReadyJs). It is NOT a PSR-7
 * response; it previously `extends ResponseInterface`, a compile-time fatal
 * the moment the class was autoloaded.
 */
class BuilderResponse {
    
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
