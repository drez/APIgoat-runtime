<?php

namespace ApiGoat\Utility;

class Menu
{

    public $subTabs = [];
    public $tabs = [];
    public $menu = '';

    private $requested = false;
    private $indexMenu = 0;
    private $underIndex = 0;

    public function __construct($requested, $defaultClass = [])
    {
        $this->requested = $requested;
    }

    public function addItem($Name, $Model = '')
    {
        if (empty($Model)) {
            $Model = $Name;
        }
        if (!isset($this->tabs[$Model])) {
            $this->tabs[$Model] = $Name;
        }
    }

    public function addUnder($Parent, $Name, $Model, $Position = 0)
    {
        if ($_SESSION[_AUTH_VAR]->get('group') === 'Admin' || $_SESSION[_AUTH_VAR]->hasMenu($Model)) {
            $class = '';
            if ($this->requested == $Model) {
                $class = 'active';
            }
            $this->underIndex++;

            $this->subTabs[$Parent][$this->underIndex][0] =
                li(
                    htmlLink(_($Name), _SITE_URL . $Model, " class='" . $class . "' j=sm_a "),
                    "  title='" . _($Name) . "' j='sm'  id='menu_" . $Model . "'  "
                );

            $this->subTabs[$Parent][$this->underIndex][1] = $Position;
        }
    }

    private function buildSubMenu($subTabs)
    {
        $subTabsLi = '';
        if ($subTabs) {
            foreach ($subTabs as $key => $row) {
                $pos_t[$key]  = $row[1];
            }
            array_multisort($pos_t, SORT_DESC, $subTabs);
            foreach ($subTabs as $key => $row) {
                $subTabsLi .= $row[0];
            }
        }
        return $subTabsLi;
    }

    public function getMenu()
    {
        if ($_SESSION[_AUTH_VAR]->get('connected') == 'YES') {
            foreach ($this->tabs as $Model => $Name) {

                if ($_SESSION[_AUTH_VAR]->get('group') === 'Admin' || $_SESSION[_AUTH_VAR]->hasMenu($Model)) {
                    $link = _SITE_URL . $Model . '';
                    $tabsSub = "";

                    $parentCount = $count = '';
                    if ($this->requested  == $Model) {
                        $class = 'active';
                    } else {
                        $class = '';
                    }

                    if ($this->subTabs[$Model]) {
                        $tabsSub = ul(
                            $this->buildSubMenu($this->subTabs[$Model]),
                            ' class="sub-menu" '
                        );
                        $link = 'javascript:void(0);';
                    } else {
                        if ($alertsCount[$Model]) {
                            $count = span($alertsCount[$Model], 'data-entity="' . $Model . '" class="ac-alert-count"');
                        }
                    }
                    if (strpos($tabsSub, 'ac-alert-count') && empty($count)) {
                        $parentCount = span('!', 'class="ac-alert-count"');
                    }

                    $this->menu .= li(
                        htmlLink(_($Name) . $parentCount . $count, $link, "data-nav='" . $this->indexMenu . "'  j='menu' entite='" . $Model . "' id='menu_" . $Model . "' title='" . _($Name) . "' class='" . $class . "'")
                            . $tabsSub,
                        ' id="menu_' . $Model . '" '
                    );
                    $this->indexMenu++;
                }
            }

            $this->menu = ul($this->menu . li(href(_("Logout"), _SITE_URL . 'Authy/logout', 'class="disconnect"')), "class='ac-menu' ");
        }

        return $this->menu;
    }

    public function getUnderArray()
    {

        return $this->subTabs;
    }
}
