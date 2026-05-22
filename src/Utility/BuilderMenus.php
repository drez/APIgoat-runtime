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


    public function __construct($args)
    {
        $parents = [];
        $folded = $groupIcon = $groupColor = [];
        require _BASE_DIR . "config/menus.php";
        $Menu = new Menu($args['p']);

        foreach ($menus as $item) {
            if ($item['parent_menu']) {
                $Menu->addUnder($item['parent_menu'], _($item['desc']), $item['name'], $item['index'], $item['subtitle'] ?? null, $item['icon'] ?? null);
                $parents[$item['parent_menu']] = true;
                $p = $item['parent_menu'];
                // The emitter stamps identical folded/group_icon/group_color
                // values on every row of a group, so last-write-wins is safe.
                if (!empty($item['folded']))     { $folded[$p]     = true; }
                if (isset($item['group_icon']))  { $groupIcon[$p]  = $item['group_icon']; }
                if (isset($item['group_color'])) { $groupColor[$p] = $item['group_color']; }
            } else {
                $Menu->addItem(_($item['desc']), $item['name'], $item['icon'] ?? null);
                unset($parents[$item['name']]);
            }
        }

        foreach ($parents as $name => $parent) {
            $Menu->addItem(_($name), $name);
        }

        $Menu->foldedGroups = $folded;
        $Menu->groupIcon = $groupIcon;
        $Menu->groupColor = $groupColor;

        $this->Menu = $Menu;
    }

    public function getMenus()
    {
        return $this->Menu->getMenu();
    }

    public function getRequested()
    {
        return $this->Menu->getRequested();
    }
}