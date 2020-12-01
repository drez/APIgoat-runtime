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

    public function log($facility = 'info', $log, $context = [])
    {
        if (!empty($log)) {
            $this->addMessages($log, $facility, false);
        }
    }

    public function getMessages($facility = 'all')
    {
        switch ($facility) {
            case 'all':
                return $this->output;
                break;
            default:
                foreach ($this->output as $id => $arMsg) {
                    foreach ($arMsg as $name => $msg) {
                        if ($name == $facility) {
                            $output[] = $msg;
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
        if (!empty($log)) {
            $this->output[0]['info'][] = $str;
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
                    $this->output[$this->index][$facility][] = $this->replaceAbsPath($entry);
                }
            }
        } else {
            if (!empty($log)) {
                $this->output[$this->index][$facility][] = $this->replaceAbsPath($log);
            }
        }
        if ($increment) {
            $this->index++;
        }
    }

    public function mergeMessages(Object $Message)
    {
        $messages = $Message->getMessages();
        $this->output = array_merge($this->output, $messages);
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
