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
        'p' => '',
        'action' => '',
    ];
    private $containerId = "";
    private $error = [];
    private $returnType = "";
    private $messages;
    private $returnFunction;
    private $return = ['html' => '', 'onReadyJs' => '', 'js' => '', 'json' => ''];

    public function __construct($request = [], $error = [], $messages = null)
    {
        if ($request) {
            $this->request = $request;
            $this->error = $error;
            $this->messages = $messages;
        }

        $this->returnFunction = $this->request['a'] . "_return";
    }

    public function setReturnFunction($returnFunction)
    {
        $this->returnFunction = $returnFunction;
    }

    public function message($message, $error = false)
    {
        //complete-save
        return "sw_message('" . _($message) . "', '" . $error . "', 'search-progress');";
    }

    public function return ()
    {
        $returnfunc = $this->returnFunction;

        if (!empty($this->inError())) {
            $this->return_error();
        } else {
            $this->$returnfunc();
        }

        return $this->return;
    }

    private function delete_return()
    {
        $this->return['onReadyJs'] =
        $this->message(_('Item deleted'))
        . "
    document.body.style.cursor = 'auto';
    var __row = document.querySelector('#" . $this->request['p'] . "Table tr[rid=\"" . $this->request['i'] . "\"]');
    if (__row) { __row.remove(); }
    var __countEl = document.querySelector('#" . $this->request['p'] . "ListForm .pagination-wrapper .count span');
    var count = __countEl ? __countEl.textContent : 0;
    if (__countEl) { __countEl.textContent = (count - 1); }
    if((count-1) == 0){
        var __tbl = document.querySelector('#" . $this->request['p'] . "Table');
        if (__tbl) { __tbl.insertAdjacentHTML('beforeend', '<tr><td colspan=\"100%\"><p class=\"no-results\"><span>Nothing left</span></p></td></tr>'); }
    }
"; // update paging

        $closeDiag = '';
        if (strstr($this->request['ui'], 'Dialog')) {
            if ($this->request['diag'] != 'noclose') {
                $closeDiag = "if(window.gcScreens){gcScreens.popAfterSave(null);}";
            }
        }
        // Was computed but never emitted, so deleting from a dialog left it open.
        $this->return['onReadyJs'] .= $closeDiag;
    }

    private function update_return()
    {
        $messages = '';
        // Default so an unrecognized 'jet' value doesn't leave it undefined at
        // the `$alert_close .= $action_success` concat below.
        $action_success = '';

        if ($this->request['action'] == 'create') {
            $alert_close = "
    var __saveBtn = document.querySelector('#form" . $this->request['p'] . " #save" . $this->request['p'] . "');
    if (__saveBtn) { __saveBtn.removeAttribute('disabled'); __saveBtn.classList.remove('unsaved'); __saveBtn.style.cursor = 'auto'; }
    document.body.style.cursor = 'auto';";
        } else {
            $alert_close = "
    var __saveBtn = document.querySelector('#form" . $this->request['p'] . " #save" . $this->request['p'] . "');
    if (__saveBtn) { __saveBtn.removeAttribute('disabled'); __saveBtn.classList.remove('unsaved'); __saveBtn.style.cursor = 'auto'; }
    document.body.style.cursor = 'auto';";
        }

        if ($this->request['action'] == 'list') {
            $action_success = "document.location='" . _SITE_URL . $this->request['p'] . "'";
        } elseif (!empty($this->request['jet'])) {
            switch ($this->request['jet']) {
                case 'refreshChild':
                    $child = ($this->request['data']['tp']) ? $this->request['data']['tp'] : $this->request['p'];
                    $close_dialog = ($this->request['data']['no_close']) ?'': "if(window.gcScreens){gcScreens.popAfterSave(null);}";
                    $action_success =
                        "(function(){
                            var __qs = new URLSearchParams({ ui: '{$this->request['data']['pc']}Table', pui:'{$this->request['ui']}', pc:'{$this->request['data']['pc']}'});
                            fetch('" . _SITE_URL . "{$this->request['data']['pc']}/{$child}/{$this->request['data']['ip']}?' + __qs.toString(), {
                                credentials: 'same-origin',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            }).then(function (r) { return r.text(); }).then(function (data) {
                                var __cnt = document.getElementById('cnt{$this->request['pc']}Child');
                                if (__cnt) {
                                    // Mirror jQuery .html(data): replace markup AND execute any returned <script>.
                                    __cnt.innerHTML = '';
                                    var __tmp = document.createElement('div');
                                    __tmp.innerHTML = data;
                                    while (__tmp.firstChild) {
                                        var __n = __tmp.firstChild;
                                        if (__n.tagName === 'SCRIPT') {
                                            var __s2 = document.createElement('script');
                                            if (__n.src) { __s2.src = __n.src; } else { __s2.textContent = __n.textContent; }
                                            __tmp.removeChild(__n);
                                            __cnt.appendChild(__s2);
                                        } else {
                                            __cnt.appendChild(__n);
                                        }
                                    }
                                }
                                document.querySelectorAll('[j=conglet_{$this->request['data']['pc']}]').forEach(function (__c) {
                                    if (__c.parentElement) { __c.parentElement.className = 'ui-corner-top ui-state-default'; }
                                });
                                document.querySelectorAll('[j=conglet_{$this->request['data']['pc']}][p={$this->request['data']['tp']}]').forEach(function (__c) {
                                    if (__c.parentElement) { __c.parentElement.classList.add('ui-state-active'); }
                                });
                            });
                        })();
                        document.body.style.cursor = 'auto';
                        $close_dialog
                        ";

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

        $alert_close .= $action_success;

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
                "alertb('Alert', '" . addslashes($this->removeNl($messages)) . "');
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
            $this->message('Error:' . $this->error, true);
            return false;
        }
        return true;
    }

    private function removeNl($string)
    {
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
