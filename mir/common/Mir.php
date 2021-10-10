<?php

namespace mir\common;

use mir\common\configs\TablesModelsTrait;
use mir\common\logs\ActionsLog;
use mir\common\logs\CalculateLog;
use mir\common\logs\OutersLog;
use mir\common\sql\Sql;
use mir\config\Conf;
use mir\models\traits\WithMirTrait;
use mir\models\CalcsTableCycleVersion;
use mir\models\NonProjectCalcs;
use mir\models\Table;
use mir\models\TablesFields;
use mir\tableTypes\aTable;
use mir\tableTypes\cyclesTable;
use mir\tableTypes\globcalcsTable;
use mir\tableTypes\RealTables;
use mir\tableTypes\simpleTable;
use mir\tableTypes\tmpTable;

/**
 * Class Mir
 * @package mir\common
 */
class Mir
{
    public const VERSION = '1.2.30';


    public const TABLE_CODE_PARAMS = ['row_format', 'table_format'];
    public const FIELD_ROLES_PARAMS = ['addRoles', 'logRoles', 'webRoles', 'xmlRoles', 'editRoles', 'xmlEditRoles'];
    public const FIELD_CODE_PARAMS = ['code', 'codeSelect', 'codeAction', 'format'];
    public const TABLE_ROLES_PARAMS = [
        'csv_edit_roles',
        'csv_roles',
        'delete_roles',
        'duplicate_roles',
        'edit_roles',
        'insert_roles',
        'order_roles',
        'read_roles',
        'tree_off_roles'];
    const LANGUAGES = ["ru"];

    protected $interfaceData = [];


    /**
     * @var Conf
     */
    private $Config;
    /**
     * @var User
     */
    private $User;
    private $tablesInstances = [];
    protected $fieldsCache = [];
    protected $changedTables = [];
    protected $mirLogger;
    protected $cacheCycles = [];
    /**
     * @var array
     */
    protected $interfaceLinks = [];
    /**
     * @var array
     */
    protected $panelLinks = [];
    /**
     * @var OutersLog
     */
    protected $outersLogger;
    /**
     * @var CalculateLog
     */
    protected $CalculateLog;
    protected $fieldObjectsCachesVar;


    /**
     * Mir constructor.
     * @param Conf $Config
     * @param User|null $User $User
     */
    public function __construct(Conf $Config, User $User = null)
    {
        $this->Config = $Config;
        $this->User = $User;
        $this->CalculateLog = new CalculateLog();
    }

    public static function getTableClass($tableRow)
    {
        $table = '\\mir\\tableTypes\\' . $tableRow['type'] . 'Table';
        return $table;
    }

    public static function isRealTable($tableRow)
    {
        return is_subclass_of(static::getTableClass($tableRow), RealTables::class);
    }

    public function getInterfaceDatas()
    {
        return $this->interfaceData;
    }

    public function getInterfaceLinks()
    {
        return $this->interfaceLinks;
    }

    /**
     * TODO выделить в дочерний объект
     *
     * @param string $type data|table|json|diagramm|notify
     * @param $data
     * @param bool $refresh
     * @param array $elseData
     */
    public function addToInterfaceDatas(string $type, $data, $refresh = false, $elseData = [])
    {
        $data['refresh'] = $data['refresh'] ?? $refresh;
        $data['elseData'] = $elseData;
        $this->interfaceData[] = [$type, $data];
    }

    /**
     * @param $table
     * @param null|array $data
     * @param null|array $dataList
     * @param null|int $after
     * @throws errorException
     */
    public function actionInsert($table, $data = null, $dataList = null, $after = null)
    {
        $this->getTable($this->getTableRow($table))->actionInsert($data, $dataList, $after);
    }

    /**
     * @param array|int|string $where
     * @param bool $force
     * @return array|null
     */
    public function getTableRow($where, $force = false)
    {
        return $this->Config->getTableRow($where, $force);
    }

    /**
     * @param string|int|array $table
     * @return bool
     */
    public function tableExists($table)
    {
        return !!$this->Config->getTableRow($table);
    }

    /**
     * @param string $tableName
     */
    public function tableChanged(string $tableName)
    {
        $this->changedTables[$tableName] = true;
    }

    /**
     * @return bool
     */
    public function isAnyChages()
    {
        return !!$this->changedTables;
    }


    /**
     * @param int|string|array $table
     * @param null $extraData
     * @param bool $light - используется в isTableChanged.php
     * @return aTable
     * @throws errorException
     */
    public function getTable($table, $extraData = null, $light = false, $forceNew = false): aTable
    {
        if (is_array($table)) {
            $tableRow = $table;
        } else {
            $tableRow = $this->Config->getTableRow($table);
        }


        if (empty($tableRow)) {
            throw new errorException('Таблица [[' . $table . ']] не найдена');
        } elseif (empty($tableRow['type'])) {
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            throw new errorException('Внутренняя ошибка: не указан тип таблицы');
        }
        if (is_array($tableRow['type'])) {
            debug_print_backtrace();
            die;
        }

        $cacheString = $tableRow['id'] . ';' . $extraData;

        if ($forceNew) {
            unset($this->tablesInstances[$cacheString]);
        }
        if ($tableRow['type'] === 'tmp' && empty($extraData)) {
            /** @var tmpTable $tableTmp */
            /** @var tmpTable $table */
            $tableTmp = tmpTable::init($this, $tableRow, $this->getCycle(0, 0), $light, $extraData);
            $cacheString = $tableRow['id'] . ';' . $tableTmp->getTableRow()['sess_hash'];
            $this->tablesInstances[$cacheString] = $tableTmp;
            $isNewTable = true;
        } elseif (($isNewTable = !key_exists($cacheString, $this->tablesInstances))) {
            switch ($tableRow['type']) {
                case 'globcalcs':
                    $this->tablesInstances[$cacheString] = globcalcsTable::init($this, $tableRow, $light);
                    break;
                case 'calcs':
                    $Cycle = $this->getCycle($extraData, $tableRow['tree_node_id']);
                    $this->tablesInstances[$cacheString] = $Cycle->getTable($tableRow, $light);
                    break;
                case 'tmp':
                    /** @var tmpTable $table */
                    $this->tablesInstances[$cacheString] = tmpTable::init(
                        $this,
                        $tableRow,
                        $this->getCycle(0, 0),
                        $light,
                        $extraData
                    );
                    break;
                case 'simple':
                    $this->tablesInstances[$cacheString] = simpleTable::init($this, $tableRow, $extraData, $light);
                    break;
                case 'cycles':
                    $this->tablesInstances[$cacheString] = cyclesTable::init($this, $tableRow, $extraData, $light);
                    break;
                default:
                    errorException::criticalException(
                        "Таблица типа {$tableRow['type']} не подключена в системе",
                        $this
                    );
            }
        }

        if ($isNewTable) {
            $this->tablesInstances[$cacheString]->addCalculateLogInstance($this->CalculateLog->getChildInstance(['table' => $this->tablesInstances[$cacheString]]));
        }
        return $this->tablesInstances[$cacheString];
    }

    public function getCycle($id, $cyclesTableId)
    {
        $id = (int)$id;
        $cyclesTableId = (int)$cyclesTableId;
        $hashKey = $cyclesTableId . ':' . $id;

        if (!key_exists($hashKey, $this->cacheCycles)) {
            $this->cacheCycles[$hashKey] = new Cycle($id, $cyclesTableId, $this);
        }

        return $this->cacheCycles[$hashKey];
    }

    public function deleteCycle($id, $cyclesTableId)
    {
        $cycle = $this->getCycle($id, $cyclesTableId);
        $cycle->delete();

        $id = (int)$id;
        $cyclesTableId = (int)$cyclesTableId;
        $hashKey = $cyclesTableId . ':' . $id;

        unset($this->cacheCycles[$hashKey]);
    }

    public function getNamedModel($className, $isService = false)
    {
        return $this->getModel(TablesModelsTrait::getTableNameByModel($className), $isService);
    }

    public function getModel($tableName, $isService = false): Model
    {
        $m = $this->Config->getModel($tableName, null, $isService);

        if (key_exists(WithMirTrait::class, class_uses($m))) {
            /** @var WithMirTrait $m */
            $m->addMir($this);
        }
        return $m;
    }

    public function getConfig(): Conf
    {
        return $this->Config;
    }

    public function transactionStart()
    {
        $this->Config->getSql()->transactionStart();
    }

    public function transactionCommit()
    {
        $this->Config->getSql()->transactionCommit();
    }

    /**
     * Задание типа собираемых расчетных логов
     *
     * @param array $types
     */
    public function setCalcsTypesLog(array $types)
    {
        $this->CalculateLog->setLogTypes($types);
    }

    public function addToInterfaceLink($uri, $target, $title = "", $postData = null, $width = null, $refresh = false, $elseData = [])
    {
        $this->interfaceLinks[] = ['uri' => $uri, 'target' => $target, 'title' => $title, 'postData' => $postData, 'width' => $width, 'refresh' => $refresh, 'elseData' => $elseData];
    }

    public function addLinkPanel($link, $id, $field, $refresh)
    {
        $this->panelLinks[] = ['uri' => $link, 'id' => $id, 'field' => $field, 'refresh' => $refresh];
    }

    /* Сюда можно будет поставить общую систему кешей */
    public function getFieldsCaches($tableId, $version)
    {
        $cache = $tableId . '/' . $version;
        if (key_exists($cache, $this->fieldsCache)) {
            return $this->fieldsCache[$cache];
        }
        return null;
    }

    public function setFieldsCaches($tableId, $version, array $fields)
    {
        $cache = $tableId . '/' . $version;
        $this->fieldsCache[$cache] = $fields;
    }

    public function getUser(): User
    {
        if (!$this->User) {
            errorException::criticalException('Потеряна авторизация');
        }
        return $this->User;
    }

    public function mirActionsLogger()
    {
        if (!$this->mirLogger) {
            if (!$this->User) {
                errorException::criticalException('Нельзя проводить изменения с логированием без авторизации', $this);
            }
            $this->mirLogger = new ActionsLog($this);
        }

        return $this->mirLogger;
    }

    /**
     * @return CalculateLog
     */
    public function getCalculateLog(): CalculateLog
    {
        return $this->CalculateLog;
    }

    /**
     * @return array
     */
    public function getPanelLinks(): array
    {
        return $this->panelLinks;
    }

    public function transactionRollback()
    {
        $this->Config->getSql()->transactionRollBack();
    }

    public function fieldObjectsCaches(string $staticName, \Closure $getField)
    {
        return $this->fieldObjectsCachesVar[$staticName] ?? $this->fieldObjectsCachesVar[$staticName] = $getField();
    }


    public function getOutersLogger()
    {
        return $this->outersLogger ?? $this->outersLogger = new OutersLog($this, $this->User->getId());
    }

    public function clearTables()
    {
        $this->tablesInstances = [];
        $this->fieldsCache = [];
    }

    public function getSpecialInterface()
    {
        return null;
    }
}
