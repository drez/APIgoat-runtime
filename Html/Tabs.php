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
                    htmlLink($label, "javascript:", $params . " " . $data['param'] . " p='" . $this->entity . "' " . $this->conglet . " style='ui-tabs-anchor button-link-blue'"),
                    "class='" . $cssLi . " ui-state-default ui-corner-top " . $selectedClass . "'"
                );


                $t++;
            }

            $this->addTabsClick();

            if ($this->AjaxLoad) {
                $axContentDiv = div(div(""), "axContentDiv");
                $this->parentContentDivId = 'axContentDiv';
            }

            if (!empty($this->label)) {
                $setHalf = "max-width: 50%;";
            }

            $this->html = div(
                div($this->label, '', "style='display:inline-block;margin-right: 20px;'")
                    . ul(
                        $li,
                        "class='ui-tabs-nav ui-helper-reset ui-helper-clearfix ui-widget-header ui-corner-all'"
                    ),
                'cntOnglet' . $this->entity,
                "class='" . $this->child_pannel . "pannel_child_onglet cntOnglet HtmlTabs'"
            )
                . $axContentDiv;
        } else {
            $this->html = div(
                ul(
                    li(
                        htmlLink($label, "javascript:", " p='" . $this->entity . "' " . $this->conglet . "  class='ui-tabs-anchor button-link-blue'"),
                        "class='ui-state-default ui-corner-top ui-tabs-active ui-state-active'"
                    ),
                    "class='" . $this->child_pannel . " ui-tabs-nav ui-helper-reset ui-helper-clearfix ui-widget-header ui-corner-all'"
                ),
                'cntOnglet' . $this->entity,
                "class='pannel_child_onglet cntOnglet HtmlTabs'"
            );
        }
        return $this->html;
    }

    function addTabsClick()
    {

        if ($this->defaultSelected) {
            $defaultSelected = "
                $(\"[j='" . $this->tabsTarget . "']#" . $this->defaultSelected . "\").trigger('click');
                
            ";
        }

        if ($this->AjaxLoad) {
            $AjaxLoad = "
        $('#" . $this->parentContentDivId . "').hide();
        $(' #axContentDiv' ).children('div').html( $('<img>').attr('src', '" . _SITE_URL . "css/img/Ellipsis-3.9s-200px.svg') );
        $.post($(this).attr('load'), {}, function (data){
            $(' #axContentDiv' ).children('div').html(data);
        });
                ";
        }

        $this->onReadyJs .= "
    $('#" . $this->parentContentDivId . "').children('div').hide();
    
    $(\"[j='" . $this->tabsTarget . "']\").unbind('click');
    $(\"[j='" . $this->tabsTarget . "']\").click(function (){
        $(\"[j='" . $this->tabsTarget . "']\").parent().removeClass('selected ui-state-active');
        $('#" . $this->parentContentDivId . "').children('div').hide();
        $(this).parent().addClass('selected ui-state-active');
        $('#" . $this->parentContentDivId . " '+$(this).attr('t') ).show();
        " . $AjaxLoad . "
    });
    " . $defaultSelected . "
    ";
    }

    function getOnReadyJs()
    {
        return $this->onReadyJs;
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
