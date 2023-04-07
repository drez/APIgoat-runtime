<?php

namespace ApiGoat\Utility;

use Psr\Log\AbstractLogger;

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Message
 *
 * @author sysadmin
 */
class Message extends AbstractLogger
{

    public $status = 'failure';
    public $data = null;
    public $output = [];
    public $debug = false;

    public $index = 0;

    public function log($facility, $log, array $context = [])
    {
        $facility = ($facility) ? $facility : 'info';
        if (!empty($log)) {
            $this->addMessages($log, $facility, false);
        }
    }

    static function exception(\Throwable $t)
    {
        return "[Error]" . $t->getMessage();
    }

    public function clearPreviousError(){
        unset($this->output[0]['error']);
    }

    public function getSimpleApiMessages($facility = 'all'){
        $multiple = count($this->output);
        if($multiple == 1){
            return [
                'messages' => $this->output[0]['info'],
                'error' => $this->output[0]['error'],
            ];
        }else{
            throw new \Exception("Cant use simple form, multiple task messages used");
        }
    }

    public function getMessages($facility = 'all')
    {
        $output = [];
        switch ($facility) {
            case 'all':
                return $this->output;
                break;
            default:
                foreach ($this->output as $id => $arMsg) {
                    foreach ($arMsg as $name => $msg) {
                        if ($name == $facility) {
                            if(\is_array($msg)){
                                $output = array_merge($output, $msg);
                            }else{
                                $output[] = $msg;
                            }
                            
                        }
                    }
                }
                return $output;
                break;
        }
    }

    public function setDebug()
    {
        $this->debug = true;
    }

    public function addMessage($str)
    {
        if (!empty($str)) {
            $this->output['info'][] = $str;
        }
    }

    public function addLog($log, $facility = 'info')
    {
        if (!empty($log)) {
            $this->addMessages($log, $facility, false);
        }
    }

    /**
     * Add message(s) using the index and facility
     *
     * @param string/array $log
     * @param string $facility
     * @param boolean $increment
     * @return void
     */
    public function addMessages($log, $facility = 'info', $increment = true)
    {
        if (!is_array($this->output)) {
            unset($this->output);
            $this->output = [];
        }
        if (\is_array($log)) {
            foreach ($log as $entry) {
                if (!empty($entry)) {
                    $this->output[$facility][] = $this->replaceAbsPath($entry);
                }
            }
        } else {
            if (!empty($log)) {
                $this->output[$facility][] = $this->replaceAbsPath($log);
            }
        }
       
    }

    public function mergeMessages(Object $Message)
    {
        $messages = $Message->getMessages();
        foreach($messages as $facility => $message){
            $this->output[$facility] = array_merge($this->output[$facility] , $messages[$facility]);
        }
    }

    private function replaceAbsPath($entry)
    {
        //$entry = str_replace(realpath($this->path), './', $entry);
        //$entry = str_replace(_BASE_DIR, './', $entry);
        //$entry = str_replace(_INSTALL_PATH, './', $entry);
        //$entry = \preg_replace("/'(\/var\/www\/[a-zA-Z\/\.]+)'/", "./", $entry);
        return $entry;
    }
}
