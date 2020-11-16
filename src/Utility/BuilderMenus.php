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

    public function __construct($args)
    {
        require _BASE_DIR . "config/menus.php";
        $Menu = new Menu($args['p']);

        foreach ($menus as $item) {
            if ($item['parent_menu']) {
                $Menu->addUnder($item['parent_menu'], $item['desc'], $item['name'], $item['index']);
                $parents[$item['parent_menu']] = true;
            } else {
                $Menu->addItem($item['desc'], $item['name']);
                unset($parents[$item['name']]);
            }
        }

        foreach ($parents as $name => $parent) {
            $Menu->addItem($name, $name);
        }

        $this->Menu = $Menu;
    }

    public function getMenus()
    {
        return $this->Menu->getMenu();
    }
}
