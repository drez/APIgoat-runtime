<?php

namespace ApiGoat\Renderers;


class Rights
{

    static function getRightsTable($name, $omMap, $userRightsAr)
    {
        $trRights = '';
        foreach ($omMap as $oMentry) {
            if (!empty($oMentry['name']) && $oMentry['rights_on_table'] != 'false') {
                $right = $userRightsAr[$oMentry['name']];
                $trRights .= self::getRightsCheckboxRow($name, $oMentry['display'], 'Rights', $oMentry['name'], $right);
                unset($td);
            }
        }
        return div(
            input('checkbox', "mass-action-Rights{$name}", '', "j='mass-action-Rights' target='{$name}'")
            . label(_('Un/Check all'), "for='mass-action-Rights{$name}'")
            . table(
                thead(
                    tr(
                        th(_('Module'), "class='no-sort'")
                        . th(_('Read'), "style='width:70px;' class='no-sort'")
                        . th(_('Add'), "style='width:70px;' class='no-sort'")
                        . th(_('Update'), "style='width:70px;' class='no-sort'")
                        . th(_('Delete'), "style='width:70px;' class='no-sort'")
                    )
                )
                . $trRights,
                "class='rights-table tablesorter'"
            ),
            'Rigths' . $name
        );
    }

    static function getRightsCheckboxRow($group, $display, $column, $name, $right)
    {
        $td = self::getRightsCheckbox($group, $column, $name, 'r', ((!empty($right)) ? strstr($right, 'r') : false));
        $td .= self::getRightsCheckbox($group, $column, $name, 'a', ((!empty($right)) ? strstr($right, 'a') : false));
        $td .= self::getRightsCheckbox($group, $column, $name, 'w', ((!empty($right)) ? strstr($right, 'w') : false));
        $td .= self::getRightsCheckbox($group, $column, $name, 'd', ((!empty($right)) ? strstr($right, 'd') : false));
        return tr(td(htmlLink($display, 'Javascript:void(0);', 'class="bld-rights-modules-link"'), "j='chkRights' i='" . $group . $name . "'") . $td);
    }

    static function getRightsCheckbox($group, $column, $name, $right, bool $checked)
    {
        $check = '';
        if ($checked) {
            $check = "checked='checked'";
        }
        return td(
            input('checkbox', "{$column}{$group}-{$name}{$right}", $right, "{$check} j='rc{$column}{$group}' s='d' ent='{$group}{$name}'")
            . label('', "for='{$column}{$group}-{$name}{$right}'")
        );
    }
}