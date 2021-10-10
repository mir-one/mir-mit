<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 12.07.2018
 * Time: 11:12
 */

namespace mir\fieldTypes;

use mir\common\calculates\Calculate;
use mir\common\errorException;
use mir\common\Field;

class fieldParamsResult extends Field
{
    private const CODE_PARAMS = ['code', 'codeSelect', 'codeAction', 'format'];

    protected function checkValByType(&$val, $row, $isCheck = false)
    {
        if (!$isCheck) {
            if (!empty($row['data_src']['v'])) {
                $val=static::getDataFromDataSrc($row['data_src']['v'], $row['table_name']['v']);
            }
        }
    }

    public static function getDataFromDataSrc($data_src, $table_name)
    {
        $val = [];
        foreach ($data_src as $fName => $Vals) {
            if ($Vals['isOn']) {
                if (in_array($fName, static::CODE_PARAMS)) {
                    $val[$fName] = Calculate::parseMirCode($Vals['Val'], $table_name);
                } else {
                    $val[$fName] = $Vals['Val'];
                }
            }
        }
        return $val;
    }

    /*TODO check and remove Легаси для старых баз*/
    public function calculate(&$newVal, $oldRow, $row, $oldTbl, $tbl, $vars, $calcInit)
    {
        if (!empty($oldRow['id']) && $oldRow['id'] === 4) {
            $newVal = ['v' => ["type" => "fieldParamsResult", "showInWeb" => false]];
            return;
        }
    }

    public function getValueFromCsv($val)
    {
        throw new errorException('Для работы с полями есть таблица Обновления');
    }
}
