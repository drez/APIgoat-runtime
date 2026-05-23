<?php

namespace ApiGoat\Utility;

class Menu
{

    public $subTabs = [];
    public $tabs = [];
    public $icons = [];
    public $routeOverrides = [];
    public $menu = '';

    public $foldedGroups = [];
    public $groupIcon = [];
    public $groupColor = [];

    private $requested = false;
    private $indexMenu = 0;
    private $underIndex = 0;
    private $groupHasActive = [];

    public function __construct($requested, $defaultClass = [])
    {
        $this->requested = $requested;
    }

    /**
     * Cheap per-model row count for the .dr-item-tag chip.
     * Delegates to RowCount::forModel which holds the per-request memo
     * so the same count is shared with the drawer's child-tab strip.
     *
     * @param  string $Model
     * @return int|null  null => omit the chip
     */
    private function countFor($Model)
    {
        return RowCount::forModel($Model);
    }

    public function addCustomItem($Model, $data = [])
    {
        if (!isset($this->tabs[$Model])) {
            $this->tabs[$Model] = $data;
            return false;
        }
        return true;
    }

    public function addItem($Name, $Model = '', $Icon = null, $RouteOverride = null)
    {
        if (empty($Model)) {
            $Model = $Name;
        }
        if (!isset($this->tabs[$Model])) {
            $this->tabs[$Model] = $Name;
            if ($Icon) {
                $this->icons[$Model] = $Icon;
            }
            if ($RouteOverride !== null && $RouteOverride !== '') {
                $this->routeOverrides[$Model] = $RouteOverride;
            }
        }
    }

    public function addUnder($Parent, $Name, $Model, $Position = 0, $Subtitle = null, $Icon = null, $RouteOverride = null)
    {
        if ($_SESSION[_AUTH_VAR]->get('group') === 'Admin' || $_SESSION[_AUTH_VAR]->hasMenu($Model)) {
            $class = '';
            if ($this->requested == $Model) {
                $class = 'active';
                $this->groupHasActive[$Parent] = true;
            }
            $this->underIndex++;

            $count = '';
            $rowCount = $this->countFor($Model);
            if ($rowCount !== null) {
                $count = span((int) $rowCount, 'class="dr-item-tag"');
            }

            $glyphHtml = $Icon
                ? "<i class='" . htmlspecialchars($Icon) . "'></i>"
                : "<i class='dr-item-dot' aria-hidden='true'></i>";

            $url = ($RouteOverride !== null && $RouteOverride !== '')
                ? _SITE_URL . $RouteOverride
                : _SITE_URL . $Model;

            $this->subTabs[$Parent][$this->underIndex][0] =
                htmlLink(
                    $glyphHtml . span(_($Name), "class='dr-item-label'") . $count,
                    $url,
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
                    $link = isset($this->routeOverrides[$Model])
                        ? _SITE_URL . $this->routeOverrides[$Model]
                        : _SITE_URL . $Model;
                    $tabsSub = "";

                    $parentCount = $count = '';
                    if ($this->requested  == $Model) {
                        $class = 'active';
                    } else {
                        $class = '';
                    }

                    if ($this->subTabs[$Model]) {
                        // Parent with children → collapsible .dr-section
                        // label + .dr-sub group of .dr-item children.
                        // set_menu drives the optional icon, color accent
                        // and default fold (auto-expanded if it holds the
                        // active page).
                        $isFolded = !empty($this->foldedGroups[$Model])
                            && empty($this->groupHasActive[$Model]);
                        $groupIcon  = $this->groupIcon[$Model] ?? ($this->icons[$Model] ?? null);
                        $groupColor = $this->groupColor[$Model] ?? null;

                        $secClass = 'dr-section dr-section-foldable'
                            . ($isFolded ? ' is-folded' : '')
                            . ($groupColor ? ' has-group-color' : '');
                        $secAttr = 'class="' . $secClass . '"'
                            . ' data-menu-group="' . htmlspecialchars($Model) . '"'
                            . ($groupColor
                                ? ' style="--dr-group-color:' . htmlspecialchars($groupColor) . '"'
                                : '');

                        $iconHtml = $groupIcon
                            ? "<i class='dr-section-icon " . htmlspecialchars($groupIcon) . "'></i>"
                            : '';
                        $chevron = "<i class='dr-fold-chevron ri-arrow-down-s-line' aria-hidden='true'></i>";

                        $this->menu .= div(
                                $iconHtml . span(_($Name), "class='dr-section-label'") . $chevron,
                                '',
                                $secAttr
                            )
                            . div(
                                $this->buildSubMenu($this->subTabs[$Model]),
                                '',
                                'class="dr-sub ac-menu' . ($isFolded ? ' is-folded' : '')
                                    . '" entite="' . $Model . '" id="menu_' . $Model . '"'
                            );
                    } else {
                        $rowCount = $this->countFor($Model);
                        if ($rowCount !== null) {
                            $count = span((int) $rowCount, 'class="dr-item-tag"');
                        }
                        $iconStr = $this->icons[$Model] ?? null;
                        $glyphHtml = $iconStr
                            ? "<i class='" . htmlspecialchars($iconStr) . "'></i>"
                            : "<i class='dr-item-dot' aria-hidden='true'></i>";
                        $this->menu .= htmlLink(
                            $glyphHtml
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
