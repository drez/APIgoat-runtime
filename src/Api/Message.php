<?php

namespace ApiGoat\Api;

trait Message
{
    public $output = [];
    public $status = 'failure';
    public $data = null;
    public $index = 0;

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        return $this->status = $status;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data)
    {
        return $this->data = $data;
    }

    /**
     * Add quick messages 
     *
     * @param string $str
     * @return void
     */
    public function addMessage($str)
    {
        $this->output[0]['info'][] = $str;
    }

    /**
     * Add message(s) using the index and facility
     *
     * @param string/array $log
     * @param string $facility [info, messages, errors, debug]
     * @param boolean $increment
     * @return void
     */
    private function addMessages($log, $facility = 'info', $increment = true)
    {
        if (!is_array($this->output)) {
            unset($this->output);
            $this->output = [];
        }
        if (\is_array($log)) {
            foreach ($log as $entry) {
                $this->output[$this->index][$facility][] = $this->replaceAbsPath($entry);
            }
        } else {
            $this->output[$this->index][$facility][] = $this->replaceAbsPath($log);
        }
        if ($increment) {
            $this->index++;
        }
    }

    private function addLog($log, $facility = 'info')
    {
        $this->addMessages($log, $facility, false);
    }

    public function getMessages($facility = ['all'])
    {
        if (!is_array($facility)) {
            $facility = [$facility];
        }
        switch ($facility[0]) {
            case 'all':
                return $this->output;
                break;
            default:
                foreach ($this->output as $id => $arMsg) {
                    foreach ($arMsg as $name => $msg) {
                        if (in_array($name, $facility) || $name == $facility) {
                            $output = $msg;
                        }
                    }
                }
                return $output;
                break;
        }
    }

    public function getResponseBody()
    {
        $body['status'] = $this->getStatus();
        $body['data'] = $this->getData();
        $body['messages'] = $this->getMessages('info');
        $body['error'] = $this->getMessages('errors');
        $body['debug'] = $this->getMessages('debug');

        return $body;
    }

    private function replaceAbsPath($entry)
    {
        //$entry = str_replace(realpath($this->path), '.../', $entry);
        //$entry = str_replace(_BASE_DIR, '.../', $entry);
        //$entry = str_replace(_INSTALL_PATH, '.../', $entry);
        //$entry = \preg_replace("/'(\/var\/www\/[a-zA-Z\/\.]+)'/", ".../", $entry);
        return $entry;
    }
}
