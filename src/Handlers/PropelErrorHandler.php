<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace ApiGoat\Handlers;

/**
 * Description of PropelErrorHandler
 *
 * @author sysadmin
 */
class PropelErrorHandler
{

    /**
     *
     * @var array of ValidationFailed
     */
    private $failureMap = [];
    private $errorMessage = [];
    private $parentContainer = "";
    private $title = "";
    private $errorArray = [];
    /** PhpName of the model being validated (emitted Service passes it). */
    private $className = "";

    /**
     * 
     * @param array $failureMap
     * @param string $ui
     * @param string $title
     * @param array $extValidationErr
     */
    public function __construct($failureMap = null, $ui = '', $title = '', $extValidationErr = null, $className = '')
    {
        $this->failureMap = $failureMap;
        if ($failureMap) {
            $this->setValidationFailures();
        }

        if ($extValidationErr) {
            $this->setExtendedValidationFailures($extValidationErr);
        }

        $this->className = (string) $className;
        $this->setUi($ui);
        $this->setTitle($title);
    }

    /** PhpName of the model this handler was built for ('' when unknown). */
    public function getClassName()
    {
        return $this->className;
    }

    /**
     * Set the popup window title for the control panel
     *
     * @param [type] $title
     * @return void
     */
    private function setTitle($title)
    {
        $this->title = (!empty($title)) ? $title : "Validation errors";
    }

    /**
     * Set the parent container for the control panel
     *
     * @param [type] $ui
     * @return void
     */
    private function setUi($ui)
    {
        if (!empty($ui)) {
            $this->parentContainer = "#" . $ui;
            return;
        }
        // No drawer/dialog container on the request (a full-page save). Without
        // a scope the selectors below matched [v=COLUMN] anywhere on the page —
        // the list behind the form included, and any same-named field of another
        // model. The emitted Service passes its model PhpName, and the emitted
        // form is <form id="form{PhpName}">, so scope to that when we have it.
        $this->parentContainer = ($this->className !== "") ? "#form" . $this->className : "";
    }

    private function setValidationFailures()
    {
        if (!empty($this->failureMap)) {

            foreach ($this->failureMap->getValidationFailures() as $failure) {
                $msg = message_label($failure->getMessage());
                $this->errorArray['messages'][] = $msg;
                $this->errorArray['columns'][] = $failure->getColumn();
                $this->errorArray['all'][] = [$failure->getColumn() => $msg];
            }
        }
    }

    public function setExtendedValidationFailures($extValidationErr)
    {
        $fields = [];
        if (!empty($extValidationErr)) {
            $this->extValidationErr = $extValidationErr;
            $this->hasExtendedValidations = true;

            foreach ($extValidationErr as $failure => $field) {
                $msg = message_label($failure);
                $this->errorArray['messages'][] = $msg;
                $this->errorArray['columns'][] = array_merge($fields, isset($field['fields']) ? $field['fields'] : []);
                // 'all' is consumed as [fieldName => msg] (getValidationErrors ->
                // getField()). $field here is ['fields' => [...]]; using it as an
                // array key is an "Illegal offset type" fatal on PHP 8. Emit one
                // string-keyed entry per field (fall back to the failure id).
                $extFields = (isset($field['fields']) && is_array($field['fields'])) ? $field['fields'] : [(string) $failure];
                foreach ($extFields as $extField) {
                    $this->errorArray['all'][] = [$extField => $msg];
                }
            }
        }
    }

    private function getField($field)
    {
        if (strstr($field, '.')) {
            $input = explode('.', $field);
            $fieldName = $input[1];
        } else
            $fieldName = $field;
        return $fieldName;
    }

    /**
     * Return error array for API
     *
     * @return array
     */
    public function getValidationErrorsArray()
    {
        return $this->errorArray;
    }

    /**
     * Return error array for Control panel
     *
     * @return array
     */
    public function getValidationErrors()
    {
        // Seeded, not appended-to blind: 'onReadyJs' and 'txt' are only ever
        // built with .= below, so the first concat warned on a missing key.
        $this->errorMessage = ['error' => 'yes', 'onReadyJs' => '', 'txt' => ''];

        $this->errorMessage['onReadyJs'] .= "
        document.querySelectorAll('{$this->parentContainer} .error_field').forEach(function (__e) { __e.classList.remove('error_field'); });";

        foreach (($this->errorArray['all'] ?? []) as $error) {
            foreach ($error as $field => $msg) {
                if (!empty($field)) {
                    $fieldName = $this->getField($field);
                    $this->errorMessage['onReadyJs'] .= "
                    if(document.querySelector('{$this->parentContainer} [v=" . addslashes(strtoupper($fieldName)) . "] .select-label-span') !== null){
                         document.querySelectorAll('{$this->parentContainer} [v=" . addslashes(strtoupper($fieldName)) . "] .select-label-span').forEach(function (__e) { __e.classList.add('error_field'); });
                    }else{
                         document.querySelectorAll('{$this->parentContainer} [v=" . addslashes(strtoupper($fieldName)) . "]').forEach(function (__e) { __e.classList.add('error_field'); });
                    }
                ";
                    $this->errorMessage['txt'] .= $msg . "<br>";
                }
            }
        }

        $this->errorMessage['onReadyJs'] .=
            "alertb('" . addslashes($this->title) . "', '" . addslashes($this->errorMessage['txt']) . "');"
            . "alert_close = function(){
    var __ef = document.querySelector('{$this->parentContainer} .error_field');
    if (__ef) { __ef.focus(); }
    document.querySelectorAll('{$this->parentContainer} .can-save, body').forEach(function (__e) {
        __e.style.cursor = 'auto';
        __e.removeAttribute('disabled');
    });
}";

        return $this->errorMessage;
    }
}
