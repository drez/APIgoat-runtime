<?php

namespace ApiGoat\Html;

/**
 * Class to produce html and javascrit for styled tabs
 * $tabAssign = new tabs(
 *       [
 *           'States' => ['id' => 'States', 'targetDiv' => '#StatesDistribTable', 'defaultSelected' => 'true']
 *              , 'Stages' => ['id' => 'Stages', 'targetDiv' => '#StagesDistribTable'], 
 *               'Tq' => ['id' => 'Tq', 'targetDiv' => '#TqDistribTable']             
 *       ],
 *       "statsTabs",
 *       '',
 *       'stats'
 *   );
 *   $tabAssign->setParentContentDivId("StatsTables");
 *   $tabAssign->getHtml();
 */
class tabs
{
    public $html;
    public $onReadyJs;
    public $selectedId;
    public $parentEntity;
    public $parentContentDivId;
    public $AjaxLoad = false;
    public $label = false;


    public function __construct($labelData, $entity = '', $parentEntity = '')
    {
        $this->parentEntity = ($parentEntity) ? $parentEntity : 'StdTabs';

        if ($this->parentEntity) {
            $this->child_pannel = 'child_pannel';
            $this->conglet = "j='conglet_" . $this->parentEntity . "'";
            $this->tabsTarget = "conglet_" . $this->parentEntity;
        }

        $this->labelData = $labelData;
        $this->entity = $entity;
    }

    public function setLabel($label)
    {
        $this->label = $label;
    }

    function getHtml()
    {
        $setHalf = '';

        if (is_array($this->labelData)) {
            foreach ($this->labelData as $label => $data) {
                //print_r($data);
                $t = 0;
                $cssLi = '';
                $params = '';
                if ($data['selected'] == 'true') {
                    $cssLi = "selected";
                }

                if ($data['id']) {
                    $params = "id='" . $data['id'] . "'";
                } else {
                    $id = '';
                }

                $selectedClass = '';
                if ($data['targetDiv']) {
                    $params .= " t='" . $data['targetDiv'] . "'";
                    if ($data['defaultSelected']) {
                        $this->defaultSelected = $data['id'];
                        $selectedClass = 'selected';
                    }
                }

                $entity = ($data['entity']) ? $data['entity'] : $entity;

                if ($data['load']) {
                    $this->AjaxLoad = true;
                    $data['param'] .= " load='" . addslashes($data['load']) . "'";
                }

                $li .= li(
                    htmlLink($label, "javascript:void(0);", $params . " " . $data['param'] . " p='" . $this->entity . "' " . $this->conglet . " style='ui-tabs-anchor button-link-blue'"),
                    "class='" . $cssLi . " ui-state-default ui-corner-top " . $selectedClass . "'"
                );


                $t++;
            }

            $this->addTabsClick();

            if ($this->AjaxLoad) {
                $axContentDiv = div(div(""), "axContentDiv");
                $this->parentContentDivId = 'axContentDiv';
            }

            $this->html = div(
                div($this->label)
                    . div(
                        ul(
                        $li,
                        "class='ui-tabs-nav'"
                    )
                    ),
                'cntOnglet' . $this->entity,
                "class='" . $this->child_pannel . " pannel_child_onglet cntOnglet'"
            )
                . $axContentDiv;
        } else {
            $this->html = div(
                ul(
                    li(
                        htmlLink($label, "javascript:", " p='" . $this->entity . "' " . $this->conglet . "  class='ui-tabs-anchor button-link-blue'"),
                        "class='ui-state-default ui-tabs-active ui-state-active'"
                    ),
                    "class='" . $this->child_pannel . " ui-tabs-nav'"
                ),
                'cntOnglet' . $this->entity,
                "class='pannel_child_onglet cntOnglet'"
            );
        }
        return $this->html;
    }

    function addTabsClick()
    {

        if ($this->defaultSelected) {
            $defaultSelected = "
                (function () {
                    var __def = document.querySelector(\"[j='" . $this->tabsTarget . "']#" . $this->defaultSelected . "\");
                    if (__def) { __def.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })); }
                })();
            ";
        }

        if ($this->AjaxLoad) {
            $AjaxLoad = "
        var __pcd = document.getElementById('" . $this->parentContentDivId . "');
        if (__pcd) { __pcd.style.display = 'none'; }
        var __axc = document.querySelector('#axContentDiv > div');
        if (__axc) {
            var __img = document.createElement('img');
            __img.setAttribute('src', '" . _SITE_URL . "img/Ellipsis-3.9s-200px.svg');
            __axc.innerHTML = '';
            __axc.appendChild(__img);
        }
        fetch(this.getAttribute('load'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({}).toString()
        }).then(function (r) { return r.text(); }).then(function (data) {
            var __ax = document.querySelector('#axContentDiv > div');
            if (!__ax) { return; }
            // Mirror jQuery .html(data): replace markup AND execute any returned
            // <script> nodes (the ajax-loaded child panel may carry inline init
            // scripts). innerHTML alone never runs scripts.
            __ax.innerHTML = '';
            var __tmp = document.createElement('div');
            __tmp.innerHTML = data;
            while (__tmp.firstChild) {
                var __n = __tmp.firstChild;
                if (__n.tagName === 'SCRIPT') {
                    var __s2 = document.createElement('script');
                    if (__n.src) { __s2.src = __n.src; } else { __s2.textContent = __n.textContent; }
                    __tmp.removeChild(__n);
                    __ax.appendChild(__s2);
                } else {
                    __ax.appendChild(__n);
                }
            }
        });
                ";
        }

        $this->onReadyJs .= "
    (function () {
        var __sel = \"[j='" . $this->tabsTarget . "']\";
        var __pcdId = '" . $this->parentContentDivId . "';
        var __pcd0 = document.getElementById(__pcdId);
        if (__pcd0) {
            __pcd0.querySelectorAll(':scope > div').forEach(function (d) { d.style.display = 'none'; });
        }

        document.querySelectorAll(__sel).forEach(function (tab) {
            // .unbind('click') + rebind: clone-replace strips any prior listeners,
            // then attach the single click handler to the fresh node.
            var __fresh = tab.cloneNode(true);
            tab.parentNode.replaceChild(__fresh, tab);
            __fresh.addEventListener('click', function (e) {
                // Tabs are <a> links; a security pass downgrades their
                // javascript:void(0) href to '#', so a real OR synthetic
                // (defaultSelected) click would navigate to '#', fire popstate
                // and close the surrounding push-screen drawer. Never navigate.
                if (e && e.preventDefault) { e.preventDefault(); }
                document.querySelectorAll(__sel).forEach(function (t) {
                    if (t.parentNode) { t.parentNode.classList.remove('selected', 'ui-state-active'); }
                });
                var __pcd = document.getElementById(__pcdId);
                if (__pcd) {
                    __pcd.querySelectorAll(':scope > div').forEach(function (d) { d.style.display = 'none'; });
                }
                if (this.parentNode) { this.parentNode.classList.add('selected', 'ui-state-active'); }
                var __t = this.getAttribute('t');
                if (__t) {
                    var __target = document.querySelector('#' + __pcdId + ' ' + __t);
                    if (__target) { __target.style.display = ''; }
                }
                " . $AjaxLoad . "
            });
        });
    })();
    " . $defaultSelected . "
    ";
    }

    function getOnReadyJs()
    {
        // #23 S5: the tab click/init JS is no longer emitted inline. The vanilla
        // client (template screens.js bindStdTabs, bound from push()) owns the
        // [j='conglet_StdTabs'] tab toggling + initial default selection off the
        // <script> re-exec arm. The markup ($this->getHtml(), incl. the default
        // li.selected) is unchanged, so bindStdTabs has everything it needs.
        // NOTE: requires the matching template (bindStdTabs) — ship/deploy the
        // template with or before this runtime so no project loses tab switching.
        return '';
    }

    function setParentContentDivId($parentContentDivId)
    {
        $this->parentContentDivId = $parentContentDivId;
    }
    function setParentEntity($parentEntity)
    {
        $this->parentEntity = $parentEntity;
    }
}
