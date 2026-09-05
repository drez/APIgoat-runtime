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

        if ($this->inError()) {
            $this->return_error();
        } elseif (is_string($returnfunc) && $returnfunc !== '' && method_exists($this, $returnfunc)) {
            $this->$returnfunc();
        } else {
            // No `<a>_return()` for this action. Before the guard this was a
            // fatal AFTER the row had already been written (POST /X/insert
            // created the record, then died on insert_return()). Fall back to a
            // neutral "saved" acknowledgement in the standard content shape.
            error_log('BuilderReturn: no return handler "' . (string) $returnfunc . '" for action "'
                . (string) ($this->request['a'] ?? '') . '" — using the default.');
            $this->default_return();
        }

        return $this->return;
    }

    /**
     * POST /{Model}/insert. The emitted Service dispatches 'insert' to
     * saveUpdate() exactly like 'update', so the client-side outcome is the
     * same — reload/redirect onto the saved record.
     */
    private function insert_return()
    {
        $this->update_return();
    }

    /** Neutral success acknowledgement for an action with no *_return(). */
    private function default_return()
    {
        $this->return['onReadyJs'] = "document.body.style.cursor = 'auto';" . $this->message('Saved');
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

    /**
     * Error outcome. MUST keep the ['html','onReadyJs','js','json'] shape: the
     * emitted Service does `$this->content['onReadyJs'] .= ($this->content['error']
     * != 'yes') ? sw_message('Saved') : ''` and then renderXHR($this->content).
     * Replacing the shape with the bare $error list made both reads land on a
     * missing key — so a FAILED save reported "Saved" and the error text was
     * never shown. Flatten the messages, keep them under 'messages', flag
     * 'error' => 'yes' (BuilderLayout::buildXhrEnvelope reads the same key) and
     * surface the text with alertb() (never a native alert/confirm).
     */
    private function return_error()
    {
        $messages = [];
        $flat = (array) $this->error;
        array_walk_recursive(
            $flat,
            static function ($m) use (&$messages) {
                $m = trim((string) $m);
                if ($m !== '') {
                    $messages[] = $m;
                }
            }
        );

        $text = $this->removeNl(implode(' ', $messages));
        if ($text === '') {
            $text = _('The record could not be saved.');
        }

        $p = (string) ($this->request['p'] ?? '');

        $this->return = ['html' => '', 'onReadyJs' => '', 'js' => '', 'json' => ''];
        $this->return['error']    = 'yes';
        $this->return['messages'] = $messages;
        $this->return['onReadyJs'] =
            "alertb('" . addslashes(_('Alert')) . "', '" . addslashes($text) . "');
    var __saveBtn = document.querySelector('#form" . $p . " #save" . $p . "');
    if (__saveBtn) { __saveBtn.removeAttribute('disabled'); __saveBtn.style.cursor = 'auto'; }
    document.body.style.cursor = 'auto';";
    }

    /**
     * Whether the caller reported an error. The old body built a message from
     * `'Error:' . $this->error` — $error is an ARRAY, so every SUCCESSFUL save
     * raised "Array to string conversion" — and then threw the string away.
     */
    private function inError()
    {
        return ! empty($this->error);
    }

    private function removeNl($string)
    {
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
