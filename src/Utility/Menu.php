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

    /** Per-request memo of per-model row counts (model => int|null). */
    private $countCache = [];

    public function __construct($requested, $defaultClass = [])
    {
        $this->requested = $requested;
    }

    /**
     * Cheap per-model row count for the .dr-item-tag chip.
     *
     * Data-agnostic: the model name comes from menu metadata and maps to
     * the same "\App\<Model>Query" convention Api.php derives post-camelize
     * (Api::__construct camelizes the table name first; here $Model is used
     * verbatim because menu callers pass canonical model names), so no
     * entity name is hard-coded.
     *
     * Cost discipline: emits at most one un-cached "SELECT COUNT(*)" per
     * distinct visible menu model per request and memoises the result so
     * duplicate menu entries (parent + leaf) never double-query. Any
     * failure (missing Query class, no DB column, exception) yields null
     * and the chip is simply omitted — never a fatal and never an
     * unbounded number of slow queries.
     *
     * @param  string $Model
     * @return int|null  null => omit the chip
     */
    private function countFor($Model)
    {
        if (array_key_exists($Model, $this->countCache)) {
            return $this->countCache[$Model];
        }

        $count = null;
        $queryClass = '\\App\\' . $Model . 'Query';
        if (is_string($Model) && $Model !== '' && class_exists($queryClass)) {
            try {
                $count = (int) $queryClass::create()->count();
            } catch (\Throwable $e) {
                $count = null;
            }
        }

        $this->countCache[$Model] = $count;
        return $count;
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

            $count = '';
            $rowCount = $this->countFor($Model);
            if ($rowCount !== null) {
                $count = span((int) $rowCount, 'class="dr-item-tag"');
            }

            $this->subTabs[$Parent][$this->underIndex][0] =
                htmlLink(
                    "<i class='ri-circle-line'></i>" . span(_($Name), "class='dr-item-label'") . $count,
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
                        $rowCount = $this->countFor($Model);
                        if ($rowCount !== null) {
                            $count = span((int) $rowCount, 'class="dr-item-tag"');
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
