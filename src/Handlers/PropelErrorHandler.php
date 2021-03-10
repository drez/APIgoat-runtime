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
     * @var Array of ValidationFailed
     */
    private $failureMap = [];
    private $errorMessage = [];
    private $parentContainer = "";
    private $title = "";

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

        $this->setUi($ui);
        $this->setTitle($title);
        //$this->setClassName($className);
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
        $this->parentContainer = (!empty($ui)) ? "#" . $ui : "";
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
                $this->errorArray['all'][] = [$field => $msg];
            }
        }
        $this->hasExtendedValidations = false;
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
     * @return void
     */
    public function getValidationErrorsArray()
    {
        return $this->errorArray;
    }

    /**
     * Return error array for Control panel
     *
     * @return void
     */
    public function getValidationErrors()
    {
        $this->errorMessage['error'] = 'yes';

        $this->errorMessage['onReadyJs'] .= "
        $('{$this->parentContainer} .error_field').removeClass('error_field');";

        foreach ($this->errorArray['all'] as $error) {
            foreach ($error as $field => $msg) {
                if (!empty($field)) {
                    $fieldName = $this->getField($field);
                    $this->errorMessage['onReadyJs'] .= "
                    if($('{$this->parentContainer} [v=" . addslashes(strtoupper($fieldName)) . "] .select-label-span').length > 0){
                         $('{$this->parentContainer} [v=" . addslashes(strtoupper($fieldName)) . "] .select-label-span').addClass('error_field');
                    }else{
                         $('{$this->parentContainer} [v=" . addslashes(strtoupper($fieldName)) . "]').addClass('error_field');
                    }
                ";
                    $this->errorMessage['txt'] .= $msg . "<br>";
                }
            }
        }

        $this->errorMessage['onReadyJs'] .=
            "alertb('" . addslashes($this->title) . "', '" . addslashes($this->errorMessage['txt']) . "');"
            . "alert_close = function(){
    $('{$this->parentContainer} .error_field').first().focus();
    $('{$this->parentContainer} .can-save, body').css('cursor', 'auto').removeAttr('disabled');
}";

        return $this->errorMessage;
    }
}
