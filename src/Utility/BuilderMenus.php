<?php

namespace ApiGoat\Utility;

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of BuilderMenus
 *
 * @author sysadmin
 */
class BuilderMenus
{
    private $Menu;
    private $args;
    private $built = false;


    public function __construct($args)
    {
        // Menu construction is deferred: every generated service constructs a
        // BuilderMenus, but API/MCP/XHR responses never render menus. build()
        // runs on first getMenus()/getRequested() call (full-page renders only).
        $this->args = $args;
    }

    private function build(): void
    {
        if ($this->built) {
            return;
        }
        $this->built = true;
        $args = $this->args;

        $parents = [];
        $folded = $groupIcon = $groupColor = $groupDashboard = [];
        require _BASE_DIR . "config/menus.php";
        $Menu = new Menu($args['p']);

        // Group order in the drawer follows each group's first appearance in
        // $menus. Rows may carry a 'group_order' (stamped by the set_menu
        // emitter, or set directly on custom config/menus.php rows); a
        // group's order is the smallest group_order among its rows. Groups
        // without one keep their generated (alphabetical) order after the
        // ordered ones — the usort is stable, and Menu::getMenu() still pins
        // Settings last.
        $groupOrder = [];
        foreach ($menus as $item) {
            if (isset($item['group_order'])) {
                $g = $item['parent_menu'] ?: ($item['name'] ?? '');
                $o = (int) $item['group_order'];
                $groupOrder[$g] = isset($groupOrder[$g]) ? min($groupOrder[$g], $o) : $o;
            }
        }
        if ($groupOrder) {
            usort($menus, function ($a, $b) use ($groupOrder) {
                $ga = $a['parent_menu'] ?: ($a['name'] ?? '');
                $gb = $b['parent_menu'] ?: ($b['name'] ?? '');
                return ($groupOrder[$ga] ?? PHP_INT_MAX) <=> ($groupOrder[$gb] ?? PHP_INT_MAX);
            });
        }

        foreach ($menus as $item) {
            if ($item['parent_menu']) {
                $Menu->addUnder($item['parent_menu'], _($item['desc']), $item['name'], $item['index'], $item['subtitle'] ?? null, $item['icon'] ?? null, $item['route'] ?? null);
                $parents[$item['parent_menu']] = true;
                $p = $item['parent_menu'];
                // The emitter stamps identical folded/group_icon/group_color
                // values on every row of a group, so last-write-wins is safe.
                if (!empty($item['folded']))     { $folded[$p]     = true; }
                if (isset($item['group_icon']))  { $groupIcon[$p]  = $item['group_icon']; }
                if (isset($item['group_color'])) { $groupColor[$p] = $item['group_color']; }
                if (isset($item['group_dashboard'])) { $groupDashboard[$p] = $item['group_dashboard']; }
            } else {
                $Menu->addItem(_($item['desc']), $item['name'], $item['icon'] ?? null, $item['route'] ?? null);
                unset($parents[$item['name']]);
            }
        }

        foreach ($parents as $name => $parent) {
            if (!isset($Menu->tabs[$name])) {
                $Menu->addItem(_($name), $name);
            }
        }

        $Menu->foldedGroups = $folded;
        $Menu->groupIcon = $groupIcon;
        $Menu->groupColor = $groupColor;
        $Menu->groupDashboard = $groupDashboard;

        $this->Menu = $Menu;
    }

    public function getMenus()
    {
        $this->build();
        return $this->Menu->getMenu();
    }

    public function getRequested()
    {
        $this->build();
        return $this->Menu->getRequested();
    }
}