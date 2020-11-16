<?php

namespace ApiGoat\Utility;

class ArrayUtils
{

    function array_depth(array $array)
    {
        if (!(is_array($array) || $array instanceof Traversable)) {
            return "0";
        }
        $arrayiter = new \RecursiveArrayIterator($array);
        $iteriter = new \RecursiveIteratorIterator($arrayiter);
        foreach ($iteriter as $value) {
            //getDepth() start is 0, I use 0 for not iterable values
            $d = $iteriter->getDepth() + 1;
            $result[] = "$d";
        }
        return max($result);
    }
}
