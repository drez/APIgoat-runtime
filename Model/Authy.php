<?php

namespace ApiGoat\Model;

use AuthyGroupQuery;
use AuthyGroupX;

class Authy
{
    static function setDefaultsGroupRights(&$Authy)
    {
        $AuthyGroup = \App\AuthyGroupQuery::create()->findPk($Authy->getIdAuthyGroup());
        if ($AuthyGroup) {
            $Authy->setRightsAll($AuthyGroup->getRightsAll());
            $Authy->setRightsOwner($AuthyGroup->getRightsOwner());
            $Authy->setRightsGroup($AuthyGroup->getRightsGroup());
        }
    }
}
