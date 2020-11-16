<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace ApiGoat\Handlers;

/**
 * Description of ReturnApi
 *
 * @author sysadmin
 */
class ReturnApi {
    
    public function __construct($request=[], $error=[]) {
        if($request){
            $this->request = $request;
        $this->error = $error;
        }
    }
    
    public function get(){
        
    }
    
    public function set(){
        
    }
    
    public function getOne(){
        
    }
    
    public function search(){
        
    }
    
    public function put(){
        
    }
}
