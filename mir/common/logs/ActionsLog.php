<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 05.12.17
 * Time: 13:08
 */

namespace mir\common\logs;

use mir\common\Model;
use mir\common\Mir;

class ActionsLog
{
    protected const TABLE_NAME = '_log';
    /**
     * @var Mir
     */
    protected $Mir;
    /**
     * @var Model
     */
    private $model;

    public function __construct(Mir $Mir)
    {
        $this->model = $Mir->getModel(static::TABLE_NAME, true);
        $this->Mir = $Mir;
    }

    public function add($tableid, $cycleid, $rowid, array $fields)
    {
        $model = static::getModel();
        foreach ($fields as $k => $v) {
            $model->insertPrepared(
                [
                    'tableid' => $tableid,
                    'cycleid' => $cycleid ?? 0,
                    'rowid' => $rowid ?? 0,
                    'field' => $k,
                    'v' => static::getVar($v[0]),
                    'modify_text' => static::getVar($v[1]),
                    'action' => 1,
                    'userid' => $this->Mir->getUser()->getId()
                ],
                false
            );
        }
    }

    public function innerLog($tableid, $cycleid, $rowid, $fieldName, $fieldComment, $fieldValue)
    {
        $model = static::getModel();
        $model->insertPrepared(
            [
                'tableid' => $tableid,
                'cycleid' => $cycleid ?? 0,
                'rowid' => $rowid ?? 0,
                'field' => $fieldName,
                'v' => static::getVar($fieldValue),
                'modify_text' => $fieldComment,
                'action' => 6,
                'userid' => $this->Mir->getUser()->getId()
            ],
            false
        );
    }

    public function modify($tableid, $cycleid, $rowid, array $fields)
    {
        $model = static::getModel();
        foreach ($fields as $k => $v) {
            $model->insertPrepared(
                [
                    'tableid' => $tableid,
                    'cycleid' => $cycleid ?? 0,
                    'rowid' => $rowid ?? 0,
                    'field' => $k,
                    'v' => static::getVar($v[0]),
                    'modify_text' => static::getVar($v[1]),
                    'action' => 2,
                    'userid' => $this->Mir->getUser()->getId()
                ],
                false
            );
        }
    }

    public function clear($tableid, $cycleid, $rowid, array $fields)
    {
        $model = static::getModel();
        foreach ($fields as $k => $v) {
            $model->insertPrepared(
                [
                    'tableid' => $tableid,
                    'cycleid' => $cycleid ?? 0,
                    'rowid' => $rowid ?? 0,
                    'field' => $k,
                    'v' => static::getVar($v[0]),
                    'modify_text' => static::getVar($v[1]),
                    'action' => 3,
                    'userid' => $this->Mir->getUser()->getId()
                ],
                false
            );
        }
    }

    public function pin($tableid, $cycleid, $rowid, array $fields)
    {
        $model = static::getModel();
        foreach ($fields as $k => $v) {
            $model->insertPrepared(
                [
                    'tableid' => $tableid,
                    'cycleid' => $cycleid ?? 0,
                    'rowid' => $rowid ?? 0,
                    'field' => $k,
                    'v' => static::getVar($v[0]),
                    'modify_text' => static::getVar($v[1]),
                    'action' => 5,
                    'userid' => $this->Mir->getUser()->getId()
                ],
                false
            );
        }
    }

    public function delete($tableid, $cycleid, $rowid, $logText = null)
    {
        $model = static::getModel();
        $model->insertPrepared(
            [
                'tableid' => $tableid,
                'cycleid' => $cycleid ?? 0,
                'rowid' => $rowid ?? 0,
                'action' => 4,
                'userid' => $this->Mir->getUser()->getId(),
                'modify_text' => $logText
            ],
            false
        );
    }

    public function restore($tableid, $cycleid, $rowid)
    {
        $model = static::getModel();
        $model->insertPrepared(
            [
                'tableid' => $tableid,
                'cycleid' => $cycleid ?? 0,
                'rowid' => $rowid ?? 0,
                'action' => 7,
                'userid' => $this->Mir->getUser()->getId()
            ],
            false
        );
    }

    public function getLogs($tableid, $cycleid, $rowid, $field)
    {
        if (empty($cycleid)) {
            $cycleid = null;
        }

        return $this->getModel()->getAll(
            [
                'tableid' => $tableid,
                'cycleid' => $cycleid ?? 0,
                'rowid' => $rowid ?? 0,
                'field' => $field
            ],
            'v as value, modify_text, action, userid as user_modify, to_char(dt, \'DD.MM.YY HH24:MI\') as dt_modify',
            'dt'
        );
    }

    protected function getVar($v)
    {
        if (is_array($v)) {
            $s = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $s;
        } elseif (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return $v;
    }

    protected function getModel()
    {
        return $this->model;
    }
}
