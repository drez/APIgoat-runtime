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

    public function addCustomItem($Model, $data = [])
    {
        if (!isset($this->tabs[$Model])) {
            $this->tabs[$Model] = $data;
            return false;
        }
        return true;
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

    public function addUnder($Parent, $Name, $Model, $Position = 0, $Subtitle = null)
    {
        if ($_SESSION[_AUTH_VAR]->get('group') === 'Admin' || $_SESSION[_AUTH_VAR]->hasMenu($Model)) {
            $class = '';
            if ($this->requested == $Model) {
                $class = 'active';
            }
            $this->underIndex++;

            $this->subTabs[$Parent][$this->underIndex][0] =
                htmlLink(
                    "<i class='ri-circle-line'></i>" . span(_($Name), "class='dr-item-label'"),
                    _SITE_URL . $Model,
                    " class='dr-item " . $class . "' j='sm_a' entite='" . $Model . "' title='" . _($Name) . "' id='menu_" . $Model . "' "
                );

            $this->subTabs[$Parent][$this->underIndex][1] = $Position;
            $this->subTabs[$Parent][$this->underIndex][2] = $Subtitle;
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
            $currentSubtitle = null;
            foreach ($subTabs as $key => $row) {
                $subtitle = $row[2] ?? null;
                if ($subtitle !== $currentSubtitle) {
                    $currentSubtitle = $subtitle;
                    if ($currentSubtitle) {
                        $subTabsLi .= div(_($currentSubtitle), '', 'class="dr-section"');
                    }
                }
                $subTabsLi .= $row[0];
            }
        }
        return $subTabsLi;
    }

    public function getMenu()
    {
        if ($_SESSION[_AUTH_VAR]->get('connected') == 'YES') {
            foreach ($this->tabs as $Model => $Name) {

                if (is_array($Name)) {
                    $this->menu .= $Name['html'];
                    $this->indexMenu++;
                } elseif ($_SESSION[_AUTH_VAR]->get('group') === 'Admin' || $_SESSION[_AUTH_VAR]->hasMenu($Model)) {
                    $link = _SITE_URL . $Model . '';
                    $tabsSub = "";

                    $parentCount = $count = '';
                    if ($this->requested  == $Model) {
                        $class = 'active';
                    } else {
                        $class = '';
                    }

                    if ($this->subTabs[$Model]) {
                        // Parent with children → guideline .dr-section
                        // label + .dr-sub group of .dr-item children.
                        $this->menu .= div(_($Name), '', 'class="dr-section"')
                            . div(
                                $this->buildSubMenu($this->subTabs[$Model]),
                                '',
                                'class="dr-sub ac-menu" entite="' . $Model . '" id="menu_' . $Model . '"'
                            );
                    } else {
                        if (isset($alertsCount[$Model])) {
                            $count = span($alertsCount[$Model], 'class="dr-item-tag"');
                        }
                        $this->menu .= htmlLink(
                            "<i class='ri-circle-line'></i>"
                                . span(_($Name), "class='dr-item-label'")
                                . $count,
                            $link,
                            "data-nav='" . $this->indexMenu . "' j='menu' entite='" . $Model . "' id='menu_" . $Model . "' title='" . _($Name) . "' class='dr-item " . $class . "'"
                        );
                    }
                    $this->indexMenu++;
                }
            }

            // .dr-* items are wrapped by BuilderLayout in .dr-scroll;
            // keep an .ac-menu hook so shell.js' nav-link close still
            // matches. Logout moved to the guideline .dr-footer.
            $this->menu = div($this->menu, '', "class='ac-menu' ");
        }

        return $this->menu;
    }

    public function getUnderArray()
    {

        return $this->subTabs;
    }

    public function getRequested()
    {
        return $this->requested;
    }
}
