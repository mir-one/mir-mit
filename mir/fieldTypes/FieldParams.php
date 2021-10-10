<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 19.09.17
 * Time: 18:51
 */

namespace mir\fieldTypes;

use mir\common\Auth;
use mir\common\calculates\Calculate;
use mir\common\errorException;
use mir\common\Field;
use mir\common\Model;
use mir\common\sql\Sql;
use mir\common\Mir;
use mir\models\Table;
use mir\models\TablesFields;
use mir\tableTypes\JsonTables;
use mir\tableTypes\tableTypes;

class FieldParams extends Field
{
    private static $fieldDatas;
    /**
     * @var array
     */
    private $inVars;

    public function addViewValues($viewType, array &$valArray, $row, $tbl = [])
    {
        parent::addViewValues($viewType, $valArray, $row, $tbl);
        switch ($viewType) {
            case 'csv':
                throw new errorException('Для работы с полями есть таблица Обновления');
                // $valArray['v'] = "";base64_encode(json_encode($valArray['v'], JSON_UNESCAPED_UNICODE));
                break;
            case 'web':
                $valArray['v'] = 'Поле настроек';/**/
                break;
            case 'edit':

                break;
        }
    }

    public function getValueFromCsv($val)
    {
        throw new errorException('Для работы с полями есть таблица Обновления');
        /*return $val = json_decode(base64_decode($val), true);*/
    }


    public function modify($channel, $changeFlag, $newVal, $oldRow, $row = [], $oldTbl = [], $tbl = [], $isCheck = false)
    {
        $this->inVars = [];

        $r = parent::modify(
            $channel,
            $changeFlag,
            $newVal,
            $oldRow,
            $row,
            $oldTbl,
            $tbl,
            $isCheck
        );
        return $r;
    }

    public function add($channel, $inNewVal, $row = [], $oldTbl = [], $tbl = [], $isCheck = false, $vars = [])
    {
        $this->inVars = $vars;

        $r = parent::add(
            $channel,
            $inNewVal,
            $row,
            $oldTbl,
            $tbl,
            $isCheck,
            $vars
        );
        return $r;
    }

    final protected function checkValByType(&$val, $row, $isCheck = false)
    {
        if ($this->table->getTableRow()['id'] !== 2) {
            throw new errorException('Тип поля Параметры допустим только для таблицы Состав полей');
        }
        /*$val = json_decode('{"type": {"Val": "fieldParamsResult", "isOn": true}, "width": {"Val": 250, "isOn": true}, "showInWeb": {"Val": false, "isOn": true}}',
            true);*/

        if (empty($val['type']['Val'])) {
            throw new errorException('Необходимо заполнить [[тип]] поля');
        }

        $category = $row['category']['v'];
        $tableRow = $this->table->getMir()->getTableRow($row['table_id']['v']);

        if ($val['type']['Val'] === 'text') {
            $val['viewTextMaxLength']['Val'] = (int)$val['viewTextMaxLength']['Val'];
        }

        if ($category === 'footer' && !is_subclass_of(
            Mir::getTableClass($tableRow),
            JsonTables::class
        )) {
            throw new errorException('Нельзя создать поле [[футера]] [[не для рассчетных]] таблиц');
        }
    }
}
