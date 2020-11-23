<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace ApiGoat\Handlers;

/**
 * Description of BuilderReturn
 *
 * @author sysadmin
 */
class BuilderReturn
{
    private $request = [
        'ui' => '',
        'ret' => '',
        'return' => '',
        'diag' => '',
        'a' => '',
        'p' => ''
    ];
    private $containerId = "";
    private $error = [];
    private $returnType = "";
    private $return = ['html' => '', 'onReadyJs' => '', 'js' => '', 'json' => ''];

    public function __construct($request = [], $error = [], $messages = null)
    {
        if ($request) {
            $this->request = $request;
            $this->error = $error;
            $this->messages = $messages;
        }
    }

    public function message($message)
    {
        //complete-save
        return "sw_message('" . _($message) . "', true, 'search-progress');";
    }

    public function return()
    {
        $returnfunc = $this->request['a'] . "_return";
        if ($this->inError()) {
            $this->return_error();
        } else {
            $this->$returnfunc();
        }

        return $this->return;
    }

    private function delete_return()
    {
        $this->return['onReadyJs'] =
            $this->message('Item deleted')
            . "
    $('body').css('cursor', 'auto');
    $('#" . $this->request['p'] . "Table tr td[i=" . $this->request['i'] . "]').parent().hide('slow').remove();
    var count = $('#" . $this->request['p'] . "ListForm .pagination-wrapper .count span').html();
    $('#" . $this->request['p'] . "ListForm .pagination-wrapper .count span').html(count-1);
    if((count-1) == 0){
        $('#" . $this->request['p'] . "Table').append( $('<tr>').append( $('<td>', {'colspan':'100%'}).append( $('<p>', {'class':'no-results'}).append( $('<span>').html('Nothing left')) ) ) );
    }
"; // update paging

        if (strstr($this->request['ui'], 'Dialog')) {
            if ($this->request['diag'] != 'noclose') {
                $closeDiag = "$('#editDialog').dialog('close');";
            }
        }
    }

    private function update_return()
    {
        $messages = '';

        if ($this->request['action'] == 'create') {
            $alert_close = "
    $('#form" . $this->request['p'] . " #save" . $this->request['p'] . "').removeAttr('disabled').removeClass('unsaved').css('cursor', 'auto');
	$('body').css('cursor', 'auto');";
        } else {
            $alert_close = "
    $('#form" . $this->request['p'] . " #save" . $this->request['p'] . "').removeAttr('disabled').removeClass('unsaved').css('cursor', 'auto');
	$('body').css('cursor', 'auto');";
        }

        if (!empty($this->request['jet'])) {
            switch ($this->request['jet']) {
                case 'refreshChild':
                    $child = ($this->request['tp']) ? $this->request['tp'] : $this->request['p'];
                    $action_success =
                        "$.get('" . _SITE_URL . "{$this->request['pc']}/{$child}/{$this->request['ip']}', { ui: '{$this->request['pc']}Table', pui:'{$this->request['ui']}', pc:'{$this->request['pc']}'}, function(data){
                            $('#cnt{$this->request['pc']}Child').html(data);
                            $('[j=conglet_{$this->request['pc']}]').parent().attr('class','ui-corner-top ui-state-default');
                            $('[j=conglet_{$this->request['pc']}][p={$this->request['tp']}]').parent().addClass('ui-state-active');
                         });"
                        . "$('body').css('cursor', 'auto');"
                        . "$('#{$this->request['ui']}').dialog('close');";

                    break;
                case 'createReload':
                    $action_success = "document.location='" . _SITE_URL . $this->request['p'] . "/edit/{$this->request['i']}'";

                    break;
                case 'swWarn':
                    $action_success = "sw_message('Saved');";
            }
        } else {
            // save existing // reload
            $action_success = "document.location='" . _SITE_URL . $this->request['p'] . "/edit/{$this->request['i']}';";
        }

        if (is_array($this->messages)) {
            foreach ($this->messages as $message) {
                if (is_array($message)) {
                    foreach ($message as $msg) {
                        if (is_array($msg)) {
                            foreach ($msg as $text) {
                                $messages .= nl2br($text) . "<br>";
                            }
                        } else {
                            $messages .= $msg . "<br>";
                        }
                    }
                } else {
                    $messages .= $message . "<br>";
                }
            }

            if (!empty($messages)) {
                $this->return['onReadyJs'] =
                    "$('#ui-dialog-title-alertDialog').html('Alert');
$('#alert_text').show().html('" . addslashes($this->removeNl($messages)) . "');
$('#alertDialog').dialog('open');
alert_close = function (){
    {$alert_close}
}";
            } else {
            $this->return['onReadyJs'] = $action_success;
        }
        } else {
            $this->return['onReadyJs'] = $action_success;
        }
    }

    private function edit_return()
    {
    }

    private function return_error()
    {
        #popup error
        $this->return = $this->error;
    }

    private function inError()
    {
        if (empty($this->error)) {
            return false;
        }
        return true;
    }

    private function removeNl($string)
    {
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
