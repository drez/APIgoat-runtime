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

        if ($_SESSION[_AUTH_VAR]->get('isRoot')) {
            $Menu->addCustomItem('AuthSwitch', [
                'html' => div(
                    form(
                        input('text', 'IarcAutoc', $_SESSION[_AUTH_VAR]->get('username'), "  otherTabs=1 v='IARC'  rid='IARC' placeholder='" . _('USER') . "' j='autocomplete' class='ui-autocomplete-input'")
                            . input('hidden', 'Iarc', $_SESSION[_AUTH_VAR]->sessVar['IdAuthy'], "s='d'"),
                        ' id="select-box-Authy" class="select-box-authy" data-authy="' . addslashes($_SESSION[_AUTH_VAR]->sessVar['IdAuthy']) . '"'
                    ),
                    '',
                    "class='box-Authy'"
                )
            ]);
        }

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
