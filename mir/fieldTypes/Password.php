<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 18.08.17
 * Time: 17:21
 */

namespace mir\fieldTypes;

use mir\common\Field;

class Password extends Field
{
    public function getModifiedLogValue($val)
    {
        return "---";
    }
    public function getLogValue($val, $row, $tbl = [])
    {
        return "---";
    }
    public function addViewValues($viewType, array &$valArray, $row, $tbl = [])
    {
        if ($viewType !== 'edit') {
            $valArray['v'] = '';
        }
    }

    protected function modifyValue($modifyVal, $oldVal, $isCheck, $row)
    {
        if ($modifyVal === '') {
            $modifyVal = $oldVal;
        }
        return $modifyVal;
    }

    protected function checkValByType(&$val, $row, $isCheck = false)
    {
        if (!$isCheck && strlen($val) !== 32) {
            $val = md5($val);
        }
    }
}
