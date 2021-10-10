<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 14.03.17
 * Time: 10:23
 */

namespace mir\tableTypes;

use Exception;
use mir\common\calculates\CalculateAction;
use mir\common\calculates\CalculcateFormat;
use mir\common\criticalErrorException;
use mir\common\errorException;
use mir\common\Field;
use mir\common\FieldModifyItem;
use mir\common\Cycle;
use mir\common\logs\CalculateLog;
use mir\common\Model;
use mir\common\Mir;
use mir\common\User;
use mir\fieldTypes\File;
use mir\fieldTypes\Select;
use mir\models\CalcsTablesVersions;
use mir\models\TmpTables;
use mir\models\UserV;
use mir\tableTypes\traits\WebInterfaceTrait;

abstract class aTable
{
    use WebInterfaceTrait;

    protected $isTableAdding = false;

    protected const TABLES_IDS = [
        'tables' => 1,
        'tables_fields' => 2
    ];


    public const CALC_INTERVAL_TYPES = [
        'changed' => 1
        , 'all_filtered' => 2
        , 'all' => 3
        , 'no' => 4
    ];
    /**
     * @var Mir
     */
    protected $Mir;
    /**
     * @var array|bool|mixed|string|CalculateLog
     */
    protected $CalculateLog;
    /**
     * @var Model
     */
    protected $model;
    /**
     * @var User
     */
    protected $User;
    /**
     * @var array|bool|string
     */
    protected $recalculateWithALog = false;
    protected $isTableDataChanged = false;

    /**
     * @var Cycle
     */
    protected $Cycle;

    protected $tableRow;
    protected $updated;
    protected $loadedUpdated;
    protected $savedUpdated;
    protected $hash;
    protected $cachedSelects = [];
    protected $fields;
    protected $sortedFields;
    protected $orderFieldName = 'id';

    protected $filteredFields = [];

    public $isOnSaving = false;
    protected $tbl;
    protected $loadedTbl;
    protected $savedTbl;
    protected $filters;
    protected $changeIds = [
        'deleted' => [],
        'restored' => [],
        'added' => [],
        'changed' => [],
        'rowOperations' => [],
        'rowOperationsPre' => [],
    ];
    protected $onCalculating = false //Рассчитывается ли таблица - для некеширования запросов к ней
    ;
    /**
     * @var mixed|null
     */
    protected $extraData;
    /**
     * @var array|bool|int[]|mixed|string|string[]
     */
    protected $restoreView = false;
    /**
     * @var array|bool|int[]|mixed|string|string[]
     */
    protected $insertRowHash;
    /**
     * @var array|null
     */
    protected $insertRowSetData;


    protected function __construct(Mir $Mir, $tableRow, $extraData = null, $light = false, $hash = null)
    {
        $this->Mir = $Mir;
        $this->User = $Mir->getUser();
        $this->extraData = $extraData;

        $this->tableRow = $tableRow;

        $this->loadModel();

        if (!$light) {
            $this->initFields();
            $this->loadDataRow(true);
            $this->updated = $this->loadedUpdated = $this->savedUpdated = $this->getUpdated();
        }

        $this->hash = $hash;
        $this->tableRow['pagination'] = $this->tableRow['pagination'] ?? '0/0';
        $this->Mir = $Mir;
    }

    /**
     * @return array
     */
    public function getInAddRecalc(): array
    {
        return $this->inAddRecalc;
    }

    /**
     * @return array|null
     */
    public function getAnchorFilters()
    {
        return $this->anchorFilters;
    }

    public function setRestoreView(bool $true)
    {
        $this->restoreView = $true;
    }

    /**
     * @return string|void
     */
    public function getInsertRowHash()
    {
        return $this->insertRowHash;
    }

    /**
     * @param string $insertRowHash
     */
    public function setInsertRowHash($insertRowHash): void
    {
        $this->insertRowHash = $insertRowHash;
    }

    public function checkInsertRow($tableData, $data, $hashData, $setData = [], $clearField = null)
    {
        if ($tableData) {
            $this->checkTableUpdated($tableData);
        }

        if (is_array($hashData)) {
            $loadData = $hashData;
            $hash = $hashData['_ihash'];
        } elseif ($hash = $hashData) {
            $this->insertRowHash = $hash;
            $loadData = TmpTables::init($this->getMir()->getConfig())->getByHash(
                    TmpTables::serviceTables['insert_row'],
                    $this->getUser(),
                    $hash
                ) ?? [];
            if ($clearField) {
                unset($loadData[$clearField]);
            }
        } else {
            $loadData = [];
        }

        $this->insertRowSetData = array_merge(
            $loadData,
            $setData
        );

        $this->reCalculate(['channel' => 'web', 'add' => [$data], 'isCheck' => true]);

        $dataToSave = [];
        foreach ($this->tbl['rowInserted'] as $k => $v) {
            if (is_array($v)) {
                if (!empty($v['h']) || empty($this->getFields()[$k]['code'])
                    || (!empty($this->getFields()[$k]['code']) && !empty($this->getFields()[$k]['codeOnlyInAdd']) && key_exists(
                            $k,
                            $data + $loadData + $setData
                        ))) {
                    $dataToSave[$k] = $v['v'];
                }
            }
        }
        if ($this->tableRow['type'] === 'tmp') {
            $dataToSave['_hash'] = $this->hash;
        }

        TmpTables::init($this->Mir->getConfig())->saveByHash(
            TmpTables::serviceTables['insert_row'],
            $this->User,
            $hash,
            $dataToSave
        );
        return $this->tbl['rowInserted'];
    }


    /**
     * @param bool $isTableDataChanged
     */
    protected function setIsTableDataChanged(bool $isTableDataChanged): void
    {
        $this->isTableDataChanged = $isTableDataChanged;
    }

    abstract protected function loadModel();

    abstract public function loadDataRow($fromConstructor = false, $force = false);

    /**
     * Возвращает loadedUpdated для текущей таблицы в зависимости от типа таблицы из разных мест
     *
     * @return string updated
     */
    public function getUpdated()
    {
        return $this->tableRow['updated'];
    }


    public function getTableRow()
    {
        return $this->tableRow;
    }

    public function getCycle()
    {
        return null;
    }

    /*В том числе из цикла, не внутренняя*/
    public function setVersion($version, $auto_recalc)
    {
        $this->tableRow['__version'] = $version;
        $this->tableRow['__auto_recalc'] = $auto_recalc;
    }

    abstract protected function getNewTblForRecalc();

    abstract protected function loadRowsByIds(array $ids);

    /**
     * @param CalculateLog|array|string $Log
     */
    public function addCalculateLogInstance($Log)
    {
        if (is_array($Log)) {
            $this->CalculateLog = $this->CalculateLog->getChildInstance($Log);
        } elseif ($Log === 'parent') {
            if (!($this->CalculateLog = $this->CalculateLog->getParent())) {
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                die('Parent Log пустой - ошибка вложенности');
            }
        } elseif (is_object($Log)) {
            $this->CalculateLog = $Log;
        } else {
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            throw new criticalErrorException('Log пустой');
        }

        return $this->CalculateLog;
    }

    public function calcLog($Log, $key = null, $result = null)
    {
        if (is_array($Log)) {
            $this->CalculateLog = $this->CalculateLog->getChildInstance($Log);
        } elseif ($key) {
            $Log->addParam($key, $result);
            $this->CalculateLog = $Log->getParent();
        } elseif (is_object($Log)) {
            $this->CalculateLog = $Log;
        }
        return $this->CalculateLog;
    }

    public static $recalcLog = [];

    protected $cacheForActionFields = [];
    protected $filtersFromUser = [];
    protected $calculatedFilters;

    /**
     * @var array|bool|string
     */
    private $anchorFilters = [];
    /**
     * @var array|bool|string
     */
    protected $webIdInterval = [];


    public static function init(Mir $Mir, $tableRow, $extraData = null, $light = false)
    {
        return new static($Mir, $tableRow, $extraData, $light, null);
    }

    public function reCreateFromDataBase(): aTable
    {
        return $this->Mir->getTable($this->getTableRow(), null, false, true);
    }

    /**
     * @param $action String
     * @return bool
     */
    public function isUserCanAction($action)
    {
        $tableRow = $this->tableRow;

        switch ($action) {
            case 'edit':
                return !!($this->User->getTables()[$tableRow['id']] ?? null);
            case 'insert':
                if ($tableRow['insertable'] && ($this->User->getTables()[$tableRow['id']] ?? null)) {
                    if (empty($tableRow['insert_roles']) || array_intersect(
                            $tableRow['insert_roles'],
                            $this->User->getRoles()
                        )) {
                        return true;
                    }
                }
                break;
            case 'delete':
                if ($tableRow['deleting'] !== 'none' && ($this->User->getTables()[$tableRow['id']] ?? null)) {
                    if (empty($tableRow['delete_roles']) || array_intersect(
                            $tableRow['delete_roles'],
                            $this->User->getRoles()
                        )) {
                        return true;
                    }
                }
                break;
            case 'restore':
                if ($tableRow['deleting'] === 'hide' && ($this->User->getTables()[$tableRow['id']] ?? null)) {
                    if ((empty($tableRow['restore_roles']) && $this->isUserCanAction('delete')) || array_intersect(
                            $tableRow['delete_roles'],
                            $this->User->getRoles()
                        )) {
                        return true;
                    }
                }
                break;
            case 'duplicate':
                if ($tableRow['duplicating'] && ($this->User->getTables()[$tableRow['id']] ?? null)) {
                    if (empty($tableRow['duplicate_roles']) || array_intersect(
                            $tableRow['duplicate_roles'],
                            $this->User->getRoles()
                        )) {
                        return true;
                    }
                }
                break;
            case 'reorder':
                if ($tableRow['with_order_field'] && ($this->User->getTables()[$tableRow['id']] ?? null)) {
                    if (empty($tableRow['order_roles']) || array_intersect(
                            $tableRow['order_roles'],
                            $this->User->getRoles()
                        )) {
                        return true;
                    }
                }
                break;
            case 'csv':
                if (empty($tableRow['csv_roles']) || array_intersect(
                        $tableRow['csv_roles'],
                        $this->User->getRoles()
                    )) {
                    return true;
                }
                break;
            case 'csv_edit':
                if (!empty($tableRow['csv_edit_roles']) && array_intersect(
                        $tableRow['csv_edit_roles'],
                        $this->User->getRoles()
                    )) {
                    return true;
                }
                break;
        }
        return false;
    }

    /**
     * TODO подумать все поля нужны не всегда
     *
     * @param false $force
     */
    public function initFields($force = false)
    {
        if ($this->tableRow['type'] === 'calcs' && is_null($this->tableRow['__version'] ?? null)) {
            /** @var Cycle $Cycle */
            $Cycle = $this->getCycle();
            list($version, $auto) = $Cycle->addVersionForCycle($this->tableRow['name']);
            $this->setVersion($version, $auto);
        }

        $this->fields = $this->loadFields(
            $this->tableRow['id'],
            $this->tableRow['__version'] ?? null,
            !$force,
            $this->Cycle ? $this->Cycle->getId() : 0
        );

        if (!empty($this->tableRow['order_field'])) {
            $this->orderFieldName = $this->tableRow['order_field'];
        } else {
            $this->orderFieldName = 'id';
        }

        $this->sortedFields = static::sortFields($this->fields);
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->User;
    }


    protected function loadFields(int $tableId, $version = null, $withCache = true, $cycleId = 0)
    {
        if (!$withCache
            || ($tableId !== static::TABLES_IDS['tables'] && $this->Mir->getTable(static::TABLES_IDS['tables'])->isOnSaving)
            || is_null($fields = $this->Mir->getFieldsCaches($tableId, $version))) {
            $fields = [];
            $where = ['table_id' => $tableId, 'version' => $version];
            $links = [];

            foreach ($this->Mir->getModel('tables_fields__v', true)->executePrepared(
                true,
                $where,
                'name, category, data, id, title, ord',
                'ord, id'
            ) as $f) {
                ;
                $f = $fields[$f['name']] = static::getFullField($f);

                if (array_key_exists('type', $f) && $f['type'] === 'link') {
                    $links[] = $f;
                }
            }

            foreach ($links as $f) {
                if ($linkTableRow = $this->Mir->getConfig()->getTableRow($f['linkTableName'])) {
                    $linkTableId = $linkTableRow['id'];

                    if ($tableId === $linkTableId) {
                        $fForLink = $fields[$f['linkFieldName']] ?? null;
                    } elseif ($linkTableRow['type'] === 'calcs') {
                        if ($this->Mir->getConfig()->getTableRow($tableId)['type'] === 'calcs') {
                            $_version = $this->Mir->getCycle(
                                $cycleId,
                                $linkTableRow['tree_node_id']
                            )->getVersionForTable($f['linkTableName'])[0];
                        } else {
                            $_version = CalcsTablesVersions::init($this->Mir->getConfig())->getDefaultVersion($f['linkTableName']);
                        }

                        $fForLink = $this->loadFields($linkTableId, $_version)[$f['linkFieldName']];
                    } else {
                        $fForLink = $this->loadFields($linkTableId)[$f['linkFieldName']];
                    }

                    if ($fForLink) {
                        $fieldFromLinkParams = [];
                        foreach (['type', 'dectimalPlaces', 'closeIframeAfterClick', 'dateFormat', 'codeSelect',
                                     'multiple', 'codeSelectIndividual', 'buttonText', 'unitType', 'currency',
                                     'textType', 'withEmptyVal', 'multySelectView', 'dateTime', 'printTextfull',
                                     'viewTextMaxLength', 'values'
                                 ] as $fV) {
                            if (isset($fForLink[$fV])) {
                                $fieldFromLinkParams[$fV] = $fForLink[$fV];
                            }
                        }
                        if ($fieldFromLinkParams['type'] === 'button') {
                            $fieldFromLinkParams['codeAction'] = $fForLink['codeAction'];
                        } elseif ($fieldFromLinkParams['type'] === 'file') {
                            $fields[$f['name']]['fileDuplicateOnCopy'] = false;
                        }

                        $fields[$f['name']] = array_merge($fields[$f['name']], $fieldFromLinkParams);
                    } else {
                        $fields[$f['name']]['linkFieldError'] = true;
                    }
                } else {
                    $fields[$f['name']]['linkFieldError'] = true;
                }
                $fields[$f['name']]['code'] = 'Код селекта';
                if ($fields[$f['name']]['type'] === 'link') {
                    $fields[$f['name']]['type'] = 'string';
                }
            }

            foreach ($fields as &$f) {
                if ($f['category'] === 'filter') {
                    if (empty($f['codeSelect']) && !empty($f['column']) && ($column = $fields[$f['column']] ?? null)) {
                        if (isset($column['codeSelect'])) {
                            $f['codeSelect'] = $column['codeSelect'];
                        } elseif (isset($column['values'])) {
                            $f['values'] = $column['values'];
                        }
                    }
                }
            }
            $this->Mir->setFieldsCaches($tableId, $version, $fields);
        }

        return $fields;
    }

    public static function getFullField($fieldRow)
    {
        $data = json_decode($fieldRow['data'], true);
        return array_merge(
            $data ?? [],
            ['category' => $fieldRow['category'], 'name' => $fieldRow['name'], 'ord' => $fieldRow['ord'], 'id' => $fieldRow['id'], 'title' => $fieldRow['title']]
        );
    }

    public static function getFooterColumns($columns)
    {
        $footerColumns = ['' => []];
        foreach ($columns as $k => $f) {
            if (!empty($f['column'])) {
                $footerColumns[$f['column']][$k] = $f;
            } else {
                $footerColumns[''][$k] = $f;
            }
        }
        return $footerColumns;
    }

    /**
     * @param array $anchorFilters
     */
    public function setAnchorFilters($anchorFilters): void
    {
        $this->anchorFilters = $anchorFilters;
    }

    public function checkIsUserCanViewIds($channel, $ids, $removed = false)
    {
        if ($channel !== 'inner') {
            $getFiltered = $this->loadFilteredRows($channel, $ids, $removed);
            foreach ($ids as $id) {
                if (!in_array($id, $getFiltered)) {
                    errorException::criticalException(
                        'Строка с id ' . $id . ' недоступна вам с текущими настроками фильтров.',
                        $this
                    );
                }
            }
        }
    }

    public function getVisibleFields(string $channel, $sorted = false)
    {
        if ($sorted) {
            if (!key_exists($channel, $this->filteredFields) || !key_exists(
                    'sorted',
                    $this->filteredFields[$channel]
                )) {
                $this->filteredFields[$channel]['sorted'] = static::sortFields($this->getVisibleFields($channel));
            }
            return $this->filteredFields[$channel]['sorted'];
        }
        if (key_exists($channel, $this->filteredFields)) {
            return $this->filteredFields[$channel]['simple'];
        }

        switch ($channel) {
            case 'web':

                $this->filteredFields[$channel] = ['simple' => []];
                $columnsFooters = [];
                foreach ($this->fields as $fName => $field) {
                    if ($this->isField(
                        'visible',
                        $channel,
                        $field
                    )) {
                        $this->filteredFields[$channel]['simple'][$fName] = $field;

                        if ($field['category'] === 'footer' && !empty($field['column'])) {
                            $columnsFooters[] = $field;
                        }
                    } elseif ($fName === $this->tableRow['main_field']) {
                        $field['showInWeb'] = false;
                        $this->filteredFields[$channel]['simple'][$fName] = $field;
                    }
                }
                foreach ($columnsFooters as $f) {
                    if (empty($this->filteredFields[$channel]['simple'][$f['column']])) {
                        unset($this->filteredFields[$channel]['simple'][$f['name']]);
                    }
                }
                return $this->filteredFields[$channel]['simple'];

            case
            'xml':

                $this->filteredFields[$channel] = ['simple' => []];
                $columnsFooters = [];
                foreach ($this->fields as $fName => $field) {
                    if ($this->isField('visible', $channel, $field)) {
                        $this->filteredFields[$channel]['simple'][$fName] = $field;

                        if ($field['category'] === 'footer' && !empty($field['column'])) {
                            $columnsFooters[] = $field;
                        }
                    }
                }
                foreach ($columnsFooters as $f) {
                    if (empty($this->filteredFields[$channel]['simple'][$f['column']])) {
                        unset($this->filteredFields[$channel]['simple'][$f['name']]);
                    }
                }
                return $this->filteredFields[$channel]['simple'];
            default:
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                throw new errorException('Канал не обнаружен');
        }
    }

    /**
     * @return CalculateLog
     */
    public function getCalculateLog()
    {
        if (empty($this->CalculateLog)) {
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        }
        return $this->CalculateLog;
    }

    public function getUpdatedJson()
    {
        return static::formUpdatedJson($this->Mir->getUser());
    }

    public static function formUpdatedJson(User $User)
    {
        return json_encode(['dt' => date('Y-m-d H:i'), 'code' => mt_rand(), 'user' => $User->getId()]);
    }

    public function getSavedUpdated()
    {
        return $this->savedUpdated;
    }

    abstract public function createTable();

    public function reCalculateFromOvers($inVars = [], $Log = null, $level = 0)
    {
        if ($Log) {
            $this->addCalculateLogInstance($Log);
        }

        $Log = $this->calcLog(["name" => 'RECALC', 'table' => $this, 'inVars' => $inVars]);

        try {
            if ($level > 20) {
                throw new errorException('Больше 20 уровней вложенности изменения таблиц. Скорее всего зацикл пересчета');
            }
            $this->reCalculate($inVars);
            $result = $this->isTblUpdated($level);
            $this->calcLog($Log, 'result', $result ? 'changed' : 'no changed');
        } catch (Exception $exception) {
            $this->calcLog($Log, 'error', $exception->getMessage());
            throw $exception;
        }
    }

    public function __get($name)
    {
        switch ($name) {
            case 'updated':
                return $this->updated;
            case 'params':
                return $this->tbl['params'] ?? [];
            case 'addedIds':
                return array_keys($this->changeIds['added']);
            case 'deletedIds':
                return array_keys($this->changeIds['deleted']);
            case 'isOnSaving':
                return $this->isOnSaving;
        }

        $debug = debug_backtrace(0, 3);
        //array_splice($debug, 0, 1);
        throw new errorException('Запрошено несуществующее свойство [[' . $name . ']]' . print_r($debug, 1));
    }

    abstract public function addField($field);

    public function selectSourceTableAction($fieldName, $itemData)
    {
        if (empty($this->fields[$fieldName]['selectTableAction'])) {
            if (!empty($this->fields[$fieldName]['selectTable'])) {
                $row2 = '';
                if ($this->fields[$fieldName]['selectTableBaseField'] ?? false) {
                    $param = '$selId';
                    $row2 = "\n" . <<<CODE
selId: select(table: '{$this->fields[$fieldName]['selectTable']}'; field: 'id'; where: '{$this->fields[$fieldName]['selectTableBaseField']}' = #{$fieldName})
CODE;;
                } else {
                    $param = '#' . $fieldName;
                }
                $this->fields[$fieldName]['selectTableAction'] = '=: linkToPanel(table: "' . $this->fields[$fieldName]['selectTable'] . '"; id: ' . $param . ')' . $row2;
            } else {
                throw new errorException('Поле не настроено');
            }
        }

        $CA = new CalculateAction($this->fields[$fieldName]['selectTableAction']);
        try {
            $CA->execAction($fieldName, $itemData, $itemData, $this->tbl, $this->tbl, $this, 'exec');
        } catch (errorException $e) {
            $e->addPath('Таблица [[' . $this->tableRow['name'] . ']]; Поле [[' . $this->fields[$fieldName]['title'] . ']]');
            throw $e;
        }
    }

    public function getMir()
    {
        return $this->Mir;
    }


    public static function sortFields($fields)
    {
        $sortedFields = ['column' => [], 'param' => [], 'footer' => [], 'filter' => []];
        foreach ($fields as $k => $v) {
            $sortedFields[$v['category']][$k] = $v;
        }
        return $sortedFields;
    }

    public function setWebIdInterval($ids)
    {
        if ($ids && is_array($ids)) {
            $this->webIdInterval = $ids;
        }
    }

    protected $inAddRecalc = [];

    protected function reCalculate($inVars = [])
    {
        $this->onCalculating = true;
        $this->cachedSelects = [];

        $add = [];

        $modify = [];
        $setValuesToDefaults = [];
        $setValuesToPinned = [];
        $remove = [];
        $restore = [];
        $isTableAdding = $this->isTableAdding;

        $addAfter = null;
        $addWithId = false;
        $isCheck = false;
        $modifyCalculated = true;
        $duplicate = [];
        $calculate = 'changed';
        $inAddRecalc = [];

        $channel = 'inner';
        $reorder = [];
        $default = [
            'modify'
            , 'setValuesToDefaults'
            , 'inAddRecalc'
            , 'add'
            , 'remove'
            , 'restore'
            , 'isTableAdding'
            , 'isCheck'
            , 'modifyCalculated'
            , 'addAfter'
            , 'addWithId'
            , 'setValuesToPinned'
            , 'isEditFilters'
            , 'addFilters'
            , 'duplicate'
            , 'calculate'
            , 'channel'
            , 'reorder'
        ];


        $inVars = array_intersect_key($inVars, array_flip($default));
        extract($inVars);

        $this->inAddRecalc = $inAddRecalc;

        $this->setIsTableDataChanged(!!$isTableAdding);
        $modify['params'] = $modify['params'] ?? [];

        if (($this->tableRow['deleting'] ?? null) === 'none' && !empty($remove) && $channel !== 'inner') {
            throw new errorException('Удаление в таблице [[' . $this->tableRow['name'] . ']] запрещено');
        }

        $oldTbl = $this->tbl;


        $newTbl = $this->getNewTblForRecalc();

        $this->tbl = &$newTbl;


        foreach (['param', 'filter', 'column'] as $category) {
            if (!($columns = $this->sortedFields[$category] ?? []) && $category !== "column") {
                continue;
            }


            try {
                switch ($category) {
                    case 'filter':
                        $Log = $this->calcLog(["recalculate" => $category]);

                        $this->reCalculateFilters(
                            $channel,
                            false,
                            $inVars['addFilters'] ?? false,
                            $modify,
                            $setValuesToDefaults
                        );
                        $this->calcLog($Log, 'result', 'done');
                        break;
                    case 'column':
                        $modifyIds = $modify;
                        unset($modifyIds['params']);

                        $ids = array_merge(array_keys($modifyIds), $remove ?? []);
                        if ($channel !== 'inner') {
                            if ($ids) {
                                $this->tbl['rows'] = $oldTbl['rows'];
                                $this->checkIsUserCanViewIds($channel, $ids);
                                $this->tbl['rows'] = [];
                            }
                            if ($restore) {
                                $this->tbl['rows'] = $oldTbl['rows'];
                                $this->checkIsUserCanViewIds($channel, $restore, true);
                                $this->tbl['rows'] = [];
                            }
                        }

                        $this->reCalculateRows(
                            $calculate,
                            $channel,
                            $isCheck,
                            $modifyCalculated,
                            $isTableAdding,
                            $remove,
                            $restore,
                            $add,
                            $modify,
                            $setValuesToDefaults,
                            $setValuesToPinned,
                            $duplicate,
                            $reorder,
                            $addAfter,
                            $addWithId
                        );


                        break;
                    default:
                        $Log = $this->calcLog(["recalculate" => $category]);

                        foreach ($columns as $column) {
                            if ($isTableAdding) {
                                $newTbl['params'][$column['name']] = Field::init($column, $this)->add(
                                    $channel,
                                    $modify['params'][$column['name']] ?? null,
                                    $newTbl['params'],
                                    $oldTbl,
                                    $newTbl
                                );
                            } else {
                                $newVal = $modify['params'][$column['name']] ?? null;
                                $oldVal = $oldTbl['params'][$column['name']] ?? null;


                                /** @var Field $Field */
                                $Field = Field::init($column, $this);

                                $changedFlag = $Field->getModifyFlag(
                                    array_key_exists(
                                        $column['name'],
                                        $modify['params'] ?? []
                                    ),
                                    $newVal,
                                    $oldVal,
                                    array_key_exists($column['name'], $setValuesToDefaults['params'] ?? []),
                                    array_key_exists($column['name'], $setValuesToPinned['params'] ?? []),
                                    $modifyCalculated
                                );

                                $newTbl['params'][$column['name']] = $Field->modify(
                                    $channel,
                                    $changedFlag,
                                    $newVal,
                                    $oldTbl['params'] ?? [],
                                    $newTbl['params'],
                                    $oldTbl,
                                    $newTbl,
                                    $isCheck
                                );

                                $this->checkIsModified($oldVal, $newTbl['params'][$column['name']]);

                                $this->addToALogModify(
                                    $Field,
                                    $channel,
                                    $newTbl,
                                    $newTbl['params'],
                                    null,
                                    $modify['params'] ?? [],
                                    $setValuesToDefaults['params'] ?? [],
                                    $setValuesToPinned['params'] ?? [],
                                    $oldVal
                                );
                            }
                        }
                        $this->calcLog($Log, 'result', 'done');
                        break;
                }
            } catch (Exception $exception) {
                throw $exception;
            }
        }
        $this->inAddRecalc = [];
        $this->onCalculating = false;
        $this->recalculateWithALog = false;
    }


    /*Устаревшая*/
    public function getDataForXml()
    {
        $table = $this->tableRow;

        $this->reCalculate(['calculate' => aTable::CALC_INTERVAL_TYPES['changed'], 'channel' => 'xml']);

        $this->isTblUpdated();
        $data['rows'] = $this->getSortedFilteredRows('xml', 'xml');
        $data['params'] = $this->getTbl()['params'];


        $data = $this->getValuesAndFormatsForClient($data, 'xml');

        $table['params'] = $data['params'];
        $table['fields'] = $this->getVisibleFields('xml', true);
        $table['updated'] = $this->updated;
        return $table;
    }

    abstract public function saveTable();

    public function getChangedString($code)
    {
        $updated = json_decode($this->getLastUpdated(true), true);

        if ((string)$updated['code'] !== $code) {
            return ['username' => $this->Mir->getNamedModel(UserV::class)->getById($updated['user'])['fio'], 'dt' => $updated['dt'], 'code' => $updated['code']];
        } else {
            return ['no' => true];
        }
    }

    public function getByParams($params, $returnType = 'field')
    {
        $this->loadDataRow();

        $fields = $this->fields;

        $params['field'] = (array)($params['field'] ?? []);
        $params['sfield'] = (array)($params['sfield'] ?? []);
        $params['pfield'] = (array)($params['pfield'] ?? []);

        $Field = $params['field'][0] ?? $params['sfield'][0] ?? null;
        if (empty($Field)) {
            throw new errorException('Не указано поле для выборки');
        }

        if (in_array($returnType, ['list', 'field']) && count($params['field']) > 1) {
            throw new errorException('Указано больше одного поля field/sfield');
        }

        $fieldsOrder = $params['fieldOrder'] ?? array_merge($params['field'], $params['sfield'], $params['pfield']);
        $params['fieldOrder'] = $fieldsOrder;

        if (!empty($params['sfield'])) {
            $params['field'] = array_merge($params['field'], $params['sfield']);
        }
        if (!empty($params['pfield'])) {
            $params['field'] = array_merge($params['field'], $params['pfield']);
        }

        foreach ($params['field'] as $fName) {
            if (!array_key_exists($fName, $fields) && !in_array($fName, Model::serviceFields)) {
                throw new errorException('Поля [[' . $fName . ']] в таблице [[' . $this->tableRow['name'] . ']] не существует');
            }
        }

        $sectionReplaces = function ($row) use ($params) {
            $rowReturn = [];
            foreach ($params['fieldOrder'] as $fName) {
                if (!array_key_exists(
                    $fName,
                    $row
                )) {

                    // debug_print_backtrace(0, 3);
                    throw new errorException('Поле [[' . $fName . ']] не найдено');
                }

                //sfield
                if (Model::isServiceField($fName)) {
                    $rowReturn[$fName] = $row[$fName];
                } //field
                elseif (in_array($fName, $params['sfield'])) {
                    $Field = Field::init($this->fields[$fName], $this);
                    $selectValue = $Field->getSelectValue(
                        $row[$fName]['v'] ?? null,
                        $row,
                        $this->tbl
                    );
                    $rowReturn[$fName] = $selectValue;
                } //id||n||is_del
                else {
                    $rowReturn[$fName] = $row[$fName]['v'];
                }
            }

            return $rowReturn;
        };

        if (!empty($fields[$Field]) && $fields[$Field]['category'] !== 'column') {
            switch ($returnType) {
                case 'field':
                    if (!key_exists('params', $this->tbl)) {
                        return null;
                    }
                    return $sectionReplaces($this->tbl['params'] ?? [])[$Field] ?? null;
                case 'list':
                    if (!key_exists('params', $this->tbl)) {
                        return [];
                    }
                    return [$sectionReplaces($this->tbl['params'])[$Field]];
                case 'row':
                    if (!key_exists('params', $this->tbl)) {
                        return [];
                    }
                    return $sectionReplaces($this->tbl['params']);
            }
        }

        $r = $this->getByParamsFromRows($params, $returnType, $sectionReplaces);

        if (!empty($params['pfield'])) {
            $previewdatas = [];
            foreach ($params['pfield'] as $pName) {
                $previewdatas[$pName] = $this->fields[$pName]['type'];
            }
            $r['previewdata'] = $previewdatas;
        }

        return $r;
    }

    public function __call($name, $arguments)
    {
        throw new errorException('Функция ' . $name . ' не предусмотрена для этого типа таблиц');
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function getSortedFields()
    {
        return $this->sortedFields;
    }

    /**
     * @param $data
     * @param string $viewType
     * @param array|null $fieldNames list of field names
     * @return mixed
     * @throws errorException
     */
    public function getValuesAndFormatsForClient($data, $viewType = 'web', array $fieldNames = null)
    {
        $isWebViewType = in_array($viewType, ['web', 'edit', 'csv', 'print']);
        $isWithList = in_array($viewType, ['web', 'edit']);
        $isWithFormat = in_array($viewType, ['web', 'edit']);

        if ($isWebViewType) {
            $visibleFields = $this->getVisibleFields("web");
        } elseif ($viewType === 'xml') {
            $visibleFields = $this->getVisibleFields("xml");
        } else {
            $visibleFields = $this->fields;
        }

        if (is_array($fieldNames)) {
            $visibleFields = array_intersect_key($visibleFields, array_combine($fieldNames, $fieldNames));
        }
        $sortedFields = static::sortFields($visibleFields);

        if ($isWithFormat && $this->tableRow['row_format'] !== '' && $this->tableRow['row_format'] !== 'f1=:') {
            $RowFormatCalculate = new CalculcateFormat($this->tableRow['row_format']);
        }
        $data['rows'] = ($data['rows'] ?? []);
        /*TODO вынести это наружу*/
        $ids = array_unique(array_merge($this->webIdInterval, array_column($data['rows'], "id")));

        foreach ($data['rows'] as $i => $row) {

            $newRow = ['id' => ($row['id'] ?? null)];
            $rowIn = $this->tbl['rows'][$row['id'] ?? ''] ?? $row;

            if (array_key_exists('n', $row)) {
                $newRow['n'] = $row['n'];
                if (!empty($this->getTableRow()['new_row_in_sort']) && key_exists(
                        $row['id'],
                        $this->changeIds['added']
                    )) {
                    if ($this->getTableRow()['order_desc']) {
                        $newRow['__after'] = $this->getByParams(
                            ['field' => 'id',
                                'where' => [
                                    ['field' => 'n', 'operator' => '>', 'value' => $row['n']],
                                    ['field' => 'id', 'operator' => '=', 'value' => $ids]
                                ], 'order' => [['field' => 'n', 'ad' => 'asc']]],
                            'field'
                        );
                    } else {
                        $newRow['__after'] = $this->getByParams(
                            ['field' => 'id',
                                'where' => [
                                    ['field' => 'n', 'operator' => '<', 'value' => $row['n']],
                                    ['field' => 'id', 'operator' => '=', 'value' => $ids]
                                ], 'order' => [['field' => 'n', 'ad' => 'desc']]],
                            'field'
                        );
                    }
                }
            }
            if (!empty($row['InsDel'])) {
                $newRow['InsDel'] = true;
            }

            //if (empty($row['id'])) debug_print_backtrace();
            foreach ($sortedFields['column'] as $f) {
                if (empty($row[$f['name']])) {
                    continue;
                }



                if (!empty($f['notLoaded']) && $viewType === 'web') {
                    $rowIn[$f['name']]['v'] = '**NOT_LOADED**';
                }

                $newRow[$f['name']] = $row[$f['name']];

                Field::init($f, $this)->addViewValues(
                    $viewType,
                    $newRow[$f['name']],
                    $rowIn,
                    $this->tbl
                );

                if ($isWithFormat) {
                    Field::init($f, $this)->addFormat(
                        $newRow[$f['name']],
                        $rowIn,
                        $this->tbl
                    );
                }
            }

            if ($isWithFormat && !empty($RowFormatCalculate)) {
                $Log = $this->calcLog(['itemId' => $row['id'] ?? null, 'cType' => "format", 'name' => 'row']);

                $newRow['f'] = $RowFormatCalculate->getFormat(
                    'ROW',
                    $rowIn,
                    $this->tbl,
                    $this
                );
                $this->calcLog($Log, 'result', $newRow['f']);
            } else {
                $newRow['f'] = [];
            }
            $data['rows'][$i] = $newRow;
        }
        if (!empty($data['params'])) {
            $filteredParams = [];
            foreach (['param', 'footer', 'filter'] as $category) {
                foreach ($sortedFields[$category] ?? [] as $f) {
                    if (empty($data['params'][$f['name']])) {
                        continue;
                    }

                    $Field = Field::init($f, $this);

                    if ($isWithFormat) {
                        $Field->addFormat(
                            $data['params'][$f['name']],
                            $this->tbl['params'],
                            $this->tbl
                        );
                    }

                    $Field->addViewValues(
                        $viewType,
                        $data['params'][$f['name']],
                        $this->tbl['params'],
                        $this->tbl
                    );


                    if ($isWithList && $f['category'] === 'filter' && in_array($f['type'], ['select', 'tree'])) {
                        /** @var Select $Field */
                        $data['params'][$f['name']]['list'] = $Field->cropSelectListForWeb(
                            $Field->calculateSelectList(
                                $f,
                                $this->tbl['params'],
                                $this->tbl
                            ),
                            $data['params'][$f['name']]['v'],
                            ''
                        );
                    }
                    $filteredParams[$f['name']] = $data['params'][$f['name']];
                }
            }
            $data['params'] = $filteredParams;
        }


        return $data;
    }

    public function checkUnic($fieldName, $fieldVal)
    {
        if ($this->getByParams(
            ['field' => 'id', 'where' => [['field' => $fieldName, 'operator' => '=', 'value' => $fieldVal]]],
            'field'
        )) {
            return ['ok' => false];
        } else {
            return ['ok' => true];
        }
    }

    /**
     * @param null|array $data
     * @param null|array $dataList
     * @param null|int $after
     * @return array
     * @throws errorException
     */
    public function actionInsert($data = null, $dataList = null, $after = null)
    {
        $added = $this->changeIds['added'];
        if ($dataList) {
            $this->reCalculateFromOvers(['add' => $dataList, 'addAfter' => $after]);
        } elseif (!is_null($data) && is_array($data)) {
            $this->reCalculateFromOvers(['add' => [$data], 'addAfter' => $after]);
        }
        return array_keys(array_diff_key($this->changeIds['added'], $added));
    }

    public function actionSet($params, $where, $limit = null)
    {
        $modify = $this->getModifyForActionSet($params, $where, $limit);
        if ($modify) {
            $this->reCalculateFromOvers(
                [
                    'modify' => $modify
                ]
            );
        }
    }

    public function actionDuplicate($fields, $where, $limit = null, $after = null)
    {
        $ids = $this->getRemoveForActionDeleteDuplicate($where, $limit);
        if ($ids) {
            $replaces = [];
            foreach ($ids as $id) {
                $replaces[$id] = $fields;
            }
            $duplicate = [
                'ids' => $ids,
                'replaces' => $replaces
            ];

            $added = $this->changeIds['added'];
            $this->reCalculateFromOvers(
                [
                    'duplicate' => $duplicate, 'addAfter' => $after
                ]
            );
            return array_keys(array_diff_key($this->changeIds['added'], $added));
        }
        return [];
    }

    public function actionDelete($where, $limit = null)
    {
        $remove = $this->getRemoveForActionDeleteDuplicate($where, $limit);
        if ($remove) {
            $this->reCalculateFromOvers(
                [
                    'remove' => $remove
                ]
            );
        }
    }

    public function actionRestore($where, $limit = null)
    {
        $where[] = ['field' => 'is_del', 'operator' => '=', 'value' => true];
        $restore = $this->getRemoveForActionDeleteDuplicate($where, $limit);
        if ($restore) {
            $this->reCalculateFromOvers(
                [
                    'restore' => $restore
                ]
            );
        }
    }

    public function actionClear($fields, $where, $limit = null)
    {
        $setValuesToDefaults = $this->getModifyForActionClear($fields, $where, $limit);
        if ($setValuesToDefaults) {
            $this->reCalculateFromOvers(
                [
                    'setValuesToDefaults' => $setValuesToDefaults
                ]
            );
        }
    }

    public function actionPin($fields, $where, $limit = null)
    {
        $setFieldPinned = $this->getModifyForActionClear($fields, $where, $limit);

        if ($setFieldPinned) {
            $this->reCalculateFromOvers(
                [
                    'setValuesToPinned' => $setFieldPinned
                ]
            );
        }
    }


    public function getSelectByParams($params, $returnType = 'field', $rowId = null, $toSource = false)
    {
        if (empty($params['table'])) {
            throw new errorException('Не задан параметр таблица');
        }

        if (in_array(
                $returnType,
                ['field']
            ) && empty($params['field']) && empty($params['sfield'])
        ) {
            throw new errorException('Не задан параметр поле');
        }

        $sourceTableRow = $this->Mir->getTableRow($params['table']);
        if (!$sourceTableRow) {
            throw new errorException('Таблица [[' . $params['table'] . ']] не найдена');
        }

        if ($sourceTableRow['type'] === 'tmp') {
            if ($this->getTableRow()['id'] === $sourceTableRow['id']) {
                $SourceTable = $this;
            } elseif (!empty($params['hash'])) {
                $SourceTable = $this->getMir()->getTable($sourceTableRow, $params['hash']);
            } else {
                throw new errorException('Не заполнен параметр [[hash]]');
            }
        } elseif ($this->getTableRow()['type'] === 'calcs'
            && $sourceTableRow['type'] === 'calcs'
            && $sourceTableRow['tree_node_id'] === $this->getTableRow()['tree_node_id']
            && (empty($params['cycle']) || $this->Cycle->getId() === (int)$params['cycle'])

        ) {
            //TODO Проверить что будет если ошибочные данные

            /** @var Cycle $Cycle */
            $Cycle = $this->Cycle;
            $SourceTable = $Cycle->getTable($sourceTableRow);
        }//Из чужого цикла
        elseif ($sourceTableRow['type'] === 'calcs') {
            if (empty($params['cycle'])) {
                if ($this->tableRow['type'] === 'cycles' && (int)$sourceTableRow['tree_node_id'] === $this->tableRow['id'] && $rowId) {
                    $params['cycle'] = $rowId;
                } else {
                    throw new errorException('Не передан параметр [[cycle]]');
                }
            }

            if (in_array($returnType, ['list', 'rows']) && is_array($params['cycle'])) {
                $list = [];
                foreach ($params['cycle'] as $cycle) {
                    $SourceCycle = $this->Mir->getCycle($cycle, $sourceTableRow['tree_node_id']);
                    $SourceTable = $SourceCycle->getTable($sourceTableRow);
                    $list = array_merge($list, $SourceTable->getByParamsCached($params, $returnType, $this));
                }
                return $list;
            } elseif (!ctype_digit(strval($params['cycle']))) {
                throw new errorException('Параметр [[cycle]] должен быть числом');
            } else {
                $SourceCycle = $this->Mir->getCycle($params['cycle'], $sourceTableRow['tree_node_id']);
                $SourceTable = $SourceCycle->getTable($sourceTableRow);
            }
        } else {
            $SourceTable = $this->Mir->getTable($sourceTableRow);
        }

        if ($toSource && is_a($this, calcsTable::class) && is_a(
                $SourceTable,
                calcsTable::class
            ) && $this->Cycle === $SourceTable->getCycle()) {
            /** @var calcsTable $this */
            $this->addInSourceTables($sourceTableRow);
        }

        if ($returnType === 'table') {
            $replaceFilesInTblWithContent = function ($tbl, $fields) {
                $replaceFileDataWithContent = function (&$filesArray) {
                    if (!empty($filesArray['v']) && is_array($filesArray['v'])) {
                        foreach ($filesArray['v'] as &$fileData) {
                            $fileData['filestringbase64'] = base64_encode(File::getContent(
                                $fileData['file'],
                                $this->Mir->getConfig()
                            ));
                            unset($fileData['file']);
                            unset($fileData['size']);
                        }
                        unset($fileData);
                    }
                };

                foreach ($tbl['params'] as $k => &$v) {
                    if ($fields[$k]['type'] === 'file') {
                        $replaceFileDataWithContent($v);
                    }
                }
                foreach ($tbl['rows'] as &$row) {
                    foreach ($row as $k => &$v) {
                        if (($fields[$k]['type'] ?? null) === 'file') {
                            $replaceFileDataWithContent($v);
                        }
                    }
                    unset($v);
                }
                unset($row);

                return $tbl;
            };

            if (is_a($SourceTable, RealTables::class)) {
                $params['ids'] = (array)$params['ids'] ?? [];
                $fields = array_flip($params['fields'] ?? []);
                $rows = [];
                $SourceTable->loadRowsByIds($params['ids']);
                $tbl = $SourceTable->getTbl();
                $tbl['rows'] = array_intersect_key($tbl['rows'], array_flip($params['ids']));

                unset($tbl['params']['__nTailLength']);

                $tbl['params'] = array_intersect_key($tbl['params'], $fields);


                foreach ($tbl['rows'] as $_row) {
                    if ($_row['is_del'] && !key_exists('is_del', $fields)) {
                        continue;
                    }

                    $row = [];
                    foreach ($_row as $k => $v) {
                        if (key_exists($k, $fields)) {
                            $row[$k] = $v;
                        }
                        if (is_a($SourceTable, cyclesTable::class)) {
                            $row['_tables'] = [];
                            $cycle = $this->Mir->getCycle($_row['id'], $SourceTable->getTableRow()['id']);
                            foreach ($cycle->getTableIds() as $inTableID) {
                                $sourceInTable = $cycle->getTable($inTableID);
                                $row['_tables'][$sourceInTable->getTableRow()['name']] = ['tbl' => $replaceFilesInTblWithContent(
                                    $sourceInTable->getTbl(),
                                    $sourceInTable->getFields()
                                ), 'version' => $sourceInTable->getTableRow()['__version']];
                            }
                        }
                    }
                    if ($row) {
                        $rows[] = $row;
                    }
                }

                $tbl['rows'] = $rows;
                return $replaceFilesInTblWithContent($tbl, $SourceTable->getFields());
            }
            return $replaceFilesInTblWithContent($SourceTable->getTbl(), $SourceTable->getFields());
        }


        if (strpos($returnType, '&table')) {
            $returnType = str_replace('&table', '', $returnType);
            return [$SourceTable->getByParamsCached($params, $returnType, $this), $SourceTable];
        } elseif ($returnType === 'treeChildren') {
            return $SourceTable->getChildrenIds($params['id'], $params['parent'], $params['bfield'] ?? 'id');
        } else {
            return $SourceTable->getByParamsCached($params, $returnType, $this);
        }
    }

    protected function getByParamsCached($params, $returnType, aTable $fromTable)
    {
        $fromCache = false;

        if ($this->onCalculating) {
            $res = $this->getByParams($params, $returnType);
        } elseif (empty($this->cachedSelects[$hash = $returnType . serialize($params)])) {
            $res = $this->cachedSelects[$hash] = $this->getByParams($params, $returnType);
        } else {
            $fromCache = true;
            $res = $this->cachedSelects[$hash];
        }


        $p['table'] = $this;
        $p['action'] = "select";
        $p['cached'] = $fromCache;
        $p['result'] = $res;
        $p['inVars'] = $params;
        $fromTable->getCalculateLog()->getChildInstance($p);

        return $res;
    }

    public function setFilters(array $permittedFilters)
    {
        $this->filtersFromUser = [];
        foreach ($permittedFilters as $fName => $val) {
            if ($fName === 'id' || ($this->fields[$fName]['category'] ?? null) === 'filter') {
                $this->filtersFromUser[$fName] = $val;
            }
        }
    }

    abstract public function getChildrenIds($id, $parentField, $bfield);

    public function getTbl()
    {
        return $this->tbl;
    }

    /**
     * @return array
     */
    public function getChangeIds(): array
    {
        return $this->changeIds;
    }

    /**
     * @param array|bool|string $withALog
     */
    public function setWithALogTrue($logText)
    {
        $this->recalculateWithALog = is_string($logText) && $logText !== "true" ? $logText : true;
    }

    abstract protected function loadRowsByParams($params, $order = null, $offset = 0, $limit = null);


    public function loadFilteredRows($channel, $idsFilter = [], $removed = false): array
    {
        $filteredIds = [];
        $this->reCalculateFilters($channel);
        $params = $this->filtersParamsForLoadRows($channel, $idsFilter, [], true);

        if ($params !== false) {
            if ($removed) {
                $params[] = ['field' => 'is_del', 'operator' => '=', 'value' => true];
            }
            $filteredIds = $this->loadRowsByParams($params, $this->orderParamsForLoadRows());
        }
        return $filteredIds;
    }

    /**
     * Database ordering
     *
     * @param bool $asStr
     * @return array[]|string
     */
    protected function orderParamsForLoadRows($asStr = false)
    {
        $sortFieldName = 'id';
        if ($this->tableRow['order_field'] === 'n') {
            $sortFieldName = 'n';
        } elseif ($this->tableRow['order_field'] && $this->tableRow['order_field'] !== 'id') {
            if (!in_array($this->fields[$this->orderFieldName]['type'], ['select', 'tree'])) {
                $sortFieldName = $this->orderFieldName;
            }
        }
        $direction = $this->tableRow['order_desc'] ? 'desc' : 'asc';
        if ($asStr) {
            $idsPin = '';
            if (key_exists($sortFieldName, $this->fields)) {
                if ($this->fields[$sortFieldName]['type'] === 'number') {
                    $sortFieldName = "($sortFieldName->>'v')::NUMERIC";
                } else {
                    $sortFieldName = "$sortFieldName->>'v'";
                }
                $idsPin = ", id $direction";
                if ($direction === 'desc') {
                    $direction .= ' NULLS LAST';
                } else {
                    $direction .= ' NULLS FIRST';
                }
            }
            return "$sortFieldName $direction $idsPin";
        }
        $order = [['field' => $sortFieldName, 'ad' => $direction]];
        if (key_exists($sortFieldName, $this->fields)) {
            $order[] = ['field' => 'id', 'ad' => $direction];
        }
        return $order;
    }

    /**
     * @param $channel
     * @param array $idsFilter
     * @param array $elseFilters
     * @param bool $onlyBlockedFilters
     * @return array|false
     * @throws errorException
     */
    public function filtersParamsForLoadRows($channel, $idsFilter = null, $elseFilters = [], $onlyBlockedFilters = false)
    {
        $params = [];
        $issetBlockedFilters = false;

        if (!is_null($idsFilter)) {
            $params[] = ['field' => 'id', 'operator' => '=', 'value' => $idsFilter];
        }
        if (!empty($elseFilters)) {
            array_push($params, ...$elseFilters);
        }

        foreach ($this->sortedFields['filter'] ?? [] as $fName => $field) {
            if (!$this->isField('filterable', $channel, $field)) {
                continue;
            }
            if ($onlyBlockedFilters && $this->isField('editable', $channel, $field)) {
                continue;
            }

            if (!empty($field['column']) //определена колонка
                && (isset($this->sortedFields['column'][$field['column']]) || $field['column'] === 'id') //определена колонка и она существует в таблице
                && !is_null($fVal_V = $this->tbl['params'][$fName]['v']) //не "Все"
                && !(is_array($fVal_V) && count($fVal_V) === 0) //Не ничего не выбрано - не Все в мульти
                && !(!empty($idsFilter) && ((Field::init($field, $this)->isChannelChangeable(
                        'modify',
                        $channel
                    )))) // если это запрос на подтверждение прав доступа и фильтр доступен ему на редактирование
            ) {
                if ($fVal_V === '*NONE*' || (is_array($fVal_V) && in_array('*NONE*', $fVal_V))) {
                    $issetBlockedFilters = true;
                    break;
                } elseif ($fVal_V === '*ALL*' || (is_array($fVal_V) && in_array(
                            '*ALL*',
                            $fVal_V
                        )) || (!in_array(
                            $this->fields[$fName]['type'],
                            ['select', 'tree']
                        ) && $fVal_V === '')) {
                    continue;
                } else {
                    $param = [];
                    $param['field'] = $field['column'];
                    $param['value'] = $fVal_V;
                    $param['operator'] = '=';

                    if (!empty($this->fields[$fName]['intervalFilter'])) {
                        switch ($this->fields[$fName]['intervalFilter']) {
                            case  'start':
                                $param['operator'] = '>=';
                                break;
                            case  'end':
                                $param['operator'] = '<=';
                                break;
                        }
                    } //Для вебного Выбрать Пустое в мультиселекте
                    elseif (($fVal_V === [""] || $fVal_V === "")
                        && $channel === 'web'
                        && in_array($field['type'], ['select', 'tree'])
                        && (!empty($this->fields[$field['column']]['multiple']) || !empty($field['selectFilterWithEmpty']))
                        /*
                          && in_array($this->fields[$field['column']]['type'], ['select', 'tree'])
                        ($field['data']['withEmptyVal'] ?? null) || Field::isFieldListValues($this->fields[$field['column']]['type'],
                            $this->fields[$field['column']]['multiple'] ?? false)*/
                    ) {
                        $param['value'] = "";
                    }
                    $params[] = $param;
                }
            }
        }
        return $issetBlockedFilters ? false : $params;
    }

    abstract protected function getByParamsFromRows($params, $returnType, $sectionReplaces);

    protected function cropSelectListForWeb($checkedVals, $list, $isMulti, $q = '', $selectLength = 50, $topForChecked = true)
    {
        $checkedNum = 0;

        //Наверх выбранные;
        if (!empty($checkedVals)) {
            if ($isMulti) {
                foreach ((array)$checkedVals as $mm) {
                    if (array_key_exists($mm, $list) && $list[$mm][1] === 0) {
                        $v = $list[$mm];
                        unset($list[$mm]);
                        $list = [$mm => $v] + $list;
                        $checkedNum++;
                    }
                }
            } else {
                $mm = $checkedVals;
                if (array_key_exists($mm, $list) && $list[$mm][1] === 0) {
                    $v = $list[$mm];
                    unset($list[$mm]);
                    $list = [$mm => $v] + $list;
                    $checkedNum++;
                }
            }
        }

        $i = 0;
        $isSliced = false;
        $listMain = [];
        $objMain = [];
        $addInArrays = function ($k, $v) use (&$listMain, &$objMain, &$i) {
            $listMain[] = strval($k);
            unset($v[1]);
            if (!empty($v[2]) && is_object($v[2])) {
                $v[2] = $v[2]();
            }
            $objMain[$k] = array_values($v);
            $i++;
        };

        foreach ($list as $k => $v) {
            if (($v[1] ?? 0) === 1) {
                unset($list[$k]);
            }
        }

        if (count($list) > ($selectLength + $checkedNum)) {
            foreach ($list as $k => $v) {
                if ($i < $checkedNum) {
                    $addInArrays($k, $v);
                } else {
                    if ($q) {
                        if (preg_match('/' . preg_quote($q, '/') . '/ui', $v[0])) {
                            $addInArrays($k, $v);
                        }
                    } else {
                        $addInArrays($k, $v);
                    }
                }

                if ($i > $selectLength + $checkedNum) {
                    $isSliced = true;
                    break;
                }
            }
        } else {
            foreach ($list as $k => $v) {
                $addInArrays($k, $v);
            }
        }

        return ['list' => $listMain, 'indexed' => $objMain, 'sliced' => $isSliced];
    }

    protected static function isDifferentFieldData($v1, $v2)
    {
        if (is_array($v1) && is_array($v2)) {
            if (count($v1) !== count($v2)) {
                return true;
            }
            foreach ($v1 as $k => $_v1) {
                if (!key_exists($k, $v2)) {
                    return true;
                }
                if (self::isDifferentFieldData($_v1, $v2[$k])) {
                    return true;
                }
            }
            return false;
        } elseif (!is_array($v1) && !is_array($v2)) {
            if (is_numeric(strval($v1)) && is_numeric(strval($v2))) {
                return strval($v1) !== strval($v2);
            }
            return ($v1 ?? '') !== ($v2 ?? '');
        } else {
            return true;
        }
    }

    protected function checkIsModified($oldVal, $newVal)
    {
        if (!$this->isTableDataChanged) {
            if ($oldVal !== $newVal) {
                if (static::isDifferentFieldData($oldVal, $newVal)) {
                    $this->setIsTableDataChanged(true);
                }
            }
        }
    }


    /**
     * @param $channel
     * @param false $forse
     * @param bool $addFilters
     * @param array $modify
     * @param array $setValuesToDefaults
     * @throws errorException
     */
    public function reCalculateFilters(
        $channel,
        $forse = false,
        $addFilters = false,
        $modify = [],
        $setValuesToDefaults = []
    ) {
        $params = $modify['params'] ?? [];

        if ($channel === 'inner') {
            return;
        }
        switch ($channel) {
            case 'web':
                $channelParam = 'showInWeb';
                break;
            case 'xml':
                $channelParam = 'showInXml';
                break;
            default:
                throw new errorException('Channel ' . $channel . ' not defined in reCalculateFilters');
        }
        if (!$forse && key_exists($channel, $this->calculatedFilters ?? [])) {
            $this->tbl['params'] = array_merge($this->calculatedFilters[$channel], $this->tbl['params']);
        } else {
            $this->calculatedFilters[$channel] = [];


            foreach ($this->sortedFields['filter'] as $fName => $field) {
                if (!($field[$channelParam] ?? false)) {
                    continue;
                }
                if (key_exists($field['name'], $this->anchorFilters)) {
                    $this->tbl['params'][$field['name']] = ["v" => $this->anchorFilters[$field['name']]];
                    continue;
                }
                /** @var Field $Field */
                $Field = Field::init($field, $this);

                if ($addFilters !== false || !$Field->isWebChangeable('insert') || !key_exists(
                        $field['name'],
                        $params
                    )) {
                    $this->tbl['params'][$field['name']] = $Field->add(
                        'inner',
                        $addFilters[$field['name']] ?? null,
                        $this->tbl['params'],
                        $this->tbl,
                        $this->tbl
                    );
                } else {
                    $changeFlag = $Field->getModifyFlag(
                        in_array($fName, $params),
                        $params[$fName] ?? null,
                        null,
                        in_array($field['name'], $setValuesToDefaults),
                        false,
                        true
                    );

                    $this->tbl['params'][$field['name']] = $Field->modify(
                        'inner',
                        $changeFlag,
                        $params[$field['name']] ?? null,
                        [],
                        $this->tbl['params'],
                        $this->tbl,
                        $this->tbl,
                        false
                    );
                    if (key_exists('h', $this->tbl['params'][$field['name']]) && !key_exists(
                            'c',
                            $this->tbl['params'][$field['name']]
                        )) {
                        unset($this->tbl['params'][$field['name']]['h']);
                    }
                }
                $this->calculatedFilters[$channel][$field['name']] = $this->tbl['params'][$field['name']] ?? null;
            }
        }
    }


    protected function addToALogAdd(Field $Field, $channel, $newTbl, $thisRow, $modified)
    {
        if ($this->tableRow['type'] !== 'tmp' && $Field->isLogging()) {
            /*Пользователь может изменять*/
            $logIt = false;
            switch ($channel) {
                case 'web':
                    $logIt = $Field->isWebChangeable('insert');
                    break;
                case 'xml':
                    $logIt = $Field->isXmlChangeable('insert');
                    break;
                case 'inner':
                    $logIt = $this->recalculateWithALog;
                    break;
            }
            if ($logIt && key_exists($Field->getName(), $modified)) {
                //Если рассчитываемое и несовпадающее с рассчетным
                if (key_exists(
                        'c',
                        $thisRow[$Field->getName()]
                    ) || !$Field->getData('code') || $Field->getData('codeOnlyInAdd')) {
                    $this->Mir->mirActionsLogger()->add(
                        $this->tableRow['id'],
                        !empty($this->Cycle) ? $this->Cycle->getId() : null,
                        $thisRow['id'],
                        [$Field->getName() => [$Field->getLogValue(
                            $thisRow[$Field->getName()]['v'],
                            $thisRow,
                            $newTbl
                        ), $channel === 'inner' ? (is_bool($logIt) ? 'скрипт' : $logIt) : null]]
                    );
                }
            }
        }
    }

    protected function addToALogModify(Field $Field, $channel, $newTbl, $thisRow, $rowId, $modified, $setValuesToDefaults, $setValuesToPinned, $oldVal)
    {
        if ($this->tableRow['type'] !== 'tmp' && $Field->isLogging()) {
            /*Пользователь может изменять*/
            $logIt = false;
            switch ($channel) {
                case 'web':
                    $logIt = $Field->isWebChangeable('modify');
                    break;
                case 'xml':
                    $logIt = $Field->isXmlChangeable('modify');
                    break;
                case 'inner':
                    $logIt = $this->recalculateWithALog;
                    break;
            }
            if ($logIt) {
                /*Изменили*/
                if (key_exists($Field->getName(), $setValuesToDefaults)) {
                    $this->Mir->mirActionsLogger()->clear(
                        $this->tableRow['id'],
                        !empty($this->Cycle) ? $this->Cycle->getId() : null,
                        $rowId,
                        [$Field->getName() => [$Field->getLogValue(
                            $thisRow[$Field->getName()]['v'],
                            $thisRow,
                            $newTbl
                        ), $channel === 'inner' ? (is_bool($logIt) ? 'скрипт' : $logIt) : null]]
                    );
                } elseif (key_exists($Field->getName(), $setValuesToPinned)) {
                    $this->Mir->mirActionsLogger()->pin(
                        $this->tableRow['id'],
                        !empty($this->Cycle) ? $this->Cycle->getId() : null,
                        $rowId,
                        [$Field->getName() => [$Field->getLogValue(
                            $thisRow[$Field->getName()]['v'],
                            $thisRow,
                            $newTbl
                        ), $channel === 'inner' ? (is_bool($logIt) ? 'скрипт' : $logIt) : null]]
                    );
                } elseif (key_exists(
                        $Field->getName(),
                        $modified
                    ) && ($thisRow[$Field->getName()]['v'] !== $oldVal['v'] || ($thisRow[$Field->getName()]['h'] ?? null) !== ($oldVal['h'] ?? null))) {
                    $funcName = 'modify';
                    if (($thisRow[$Field->getName()]['h'] ?? null) === true && !($oldVal['h'] ?? null)) {
                        $funcName = 'pin';
                    }


                    $this->Mir->mirActionsLogger()->$funcName(
                        $this->tableRow['id'],
                        !empty($this->Cycle) ? $this->Cycle->getId() : null,
                        $rowId,
                        [$Field->getName() => [
                            $Field->getLogValue(
                                $thisRow[$Field->getName()]['v'],
                                $thisRow,
                                $newTbl
                            )
                            ,
                            $channel === 'inner' ? (is_bool($logIt) ? 'скрипт' : $logIt) : $Field->getModifiedLogValue($modified[$Field->getName()])]]
                    );
                } elseif (key_exists(
                    $Field->getName(),
                    $setValuesToDefaults
                )) {
                    $this->Mir->mirActionsLogger()->clear(
                        $this->tableRow['id'],
                        !empty($this->Cycle) ? $this->Cycle->getId() : null,
                        $rowId,
                        [$Field->getName() =>
                            [
                                $Field->getLogValue(
                                    $thisRow[$Field->getName()]['v'],
                                    $thisRow,
                                    $newTbl
                                ), $channel === 'inner' ? (is_bool($logIt) ? 'скрипт' : $logIt) : null
                            ]
                        ]
                    );
                }
            }
        }
    }

    abstract protected function isTblUpdated($level = 0, $force = false);

    protected function __getCheckSectionField($params)
    {
        $tableRow = $this->getTableRow();
        if (empty($this->fields[$params['section']])) {
            throw new errorException('Поля [[' . $params['section'] . ']] в таблице [[' . $tableRow['name'] . ']] не существует');
        }

        $sectionField = $this->fields[$params['section']];
        if ($sectionField['category'] !== 'column') {
            throw new errorException('Полe [[' . $params['section'] . ']] в таблице [[' . $tableRow['name'] . ']] не колонка');
        }
        return $sectionField;
    }

    /* abstract function actionInsert($rowParams);
    abstract function actionSet($params, $where = [], $limit = null);*/

    abstract protected function onSaveTable($tbl, $savedTbl);

    protected function getParamsWithoutFilters()
    {
        $params = $this->tbl['params'];
        foreach ($this->sortedFields['filter'] ?? [] as $fName => $field) {
            unset($params[$fName]);
        }
        return $params;
    }

    protected function getTblForSave()
    {
        $tbl = $this->tbl;
        foreach ($this->sortedFields['filter'] ?? [] as $filterField) {
            unset($tbl['params'][$filterField['name']]);
        }
        return $tbl;
    }

    public function countByParams($params, $orders = null, $untilId = 0)
    {
        if ($this->restoreView) {
            $params[] = ['field' => 'is_del', 'operator' => '=', 'value' => true];
        }
        $orders = $orders ?? $this->orderParamsForLoadRows(false);
        $ids = $this->getByParams(
            [
                'where' => $params, 'order' => $orders, 'field' => 'id'
            ],
            'list'
        );
        if ($untilId) {
            $index = array_search($untilId, $ids);
            if ($index !== false) {
                return $index + 1;
            }
        }
        return count($ids);
    }

    public function getSortedFilteredRows($channel, $viewType, $idsFilter = [], $lastId = 0, $prevLastId = 0, $onPage = null, $onlyFields = null)
    {
        $this->reCalculateFilters($channel);

        if (is_array($lastId) && key_exists('offset', $lastId)) {
            $offset = $lastId['offset'];
            $lastId = 0;
        } else {
            $offset = null;
        }

        $result = [
            'rows' => [],
            'offset' => 0,
            'allCount' => 0
        ];

        $params = $this->filtersParamsForLoadRows($channel);

        if ($params === false) {
            return $result;
        } else {
            $getRows = function ($filteredIds) {
                $rows = [];
                foreach ($filteredIds as $id) {
                    $rows[] = $this->tbl['rows'][$id];
                }
                return $rows;
            };

            $Log = $this->calcLog(['name' => 'SELECTS AND FORMATS ROWS']);

            if ($idsFilter) {
                $params[] = ['field' => 'id', 'operator' => '=', 'value' => $idsFilter];
            }
            if ($this->restoreView) {
                $params[] = ['field' => 'is_del', 'operator' => '=', 'value' => true];
            }

            if (!is_null($onPage)) {
                $orderFN = $this->getOrderFieldName();

                if (is_subclass_of($this, JsonTables::class) ||
                    (key_exists($orderFN, $this->fields)
                        && in_array($this->fields[$orderFN]['type'], ['tree', 'select']))) {
                    $filteredIds = $this->loadRowsByParams($params, $this->orderParamsForLoadRows());
                    $allCount = count($filteredIds);

                    $rows = $getRows($filteredIds);

                    $slice = function (&$rows, $pageCount) use ($onPage, $allCount, $lastId, $prevLastId, $offset) {
                        if ((int)$pageCount === 0) {
                            return 0;
                        }
                        if ($prevLastId) {
                            if ($prevLastId === -1) {
                                $offset = count($rows);
                            } else {
                                foreach ($rows as $i => $row) {
                                    if ($row['id'] === $prevLastId) {
                                        $offset = $i;
                                    }
                                }
                            }

                            if ($offset < $pageCount) {
                                if ((explode('/', $this->tableRow['pagination'])[2] ?? '') === 'desc') {
                                    $pageCount = $offset;
                                }
                                $offset = 0;
                            } else {
                                $offset -= $pageCount;
                            }
                        } elseif ($lastId !== 0 && is_null($offset)) {
                            if (is_array($lastId)) {
                                foreach ($rows as $i => $row) {
                                    if (in_array($row['id'], $lastId)) {
                                        $offset = $i;
                                        break;
                                    }
                                }
                            } elseif ($lastId === 'last') {
                                $offset = $allCount - ($allCount % $onPage ? $allCount % $onPage : $onPage);
                            } elseif ($lastId === 'desc') {
                                $offset = $allCount - $onPage;
                            } else {
                                $lastId = (int)$lastId;
                                $offset = 0;
                                foreach ($rows as $i => $row) {
                                    if ($row['id'] === $lastId) {
                                        $offset = $i + 1;
                                    }
                                }
                            }
                        }
                        $rows = array_slice($rows, $offset, $pageCount);
                        return $offset;
                    };

                    if (key_exists($orderFN, $this->fields) && in_array(
                            $this->fields[$orderFN]['type'],
                            ['tree', 'select']
                        )) {
                        $rows = $this->getValuesAndFormatsForClient(['rows' => $rows], $viewType)['rows'];
                        $this->sortRowsBydefault($rows);
                        $offset = $slice($rows, $onPage);
                    } else {
                        $offset = $slice($rows, $onPage);
                        $rows = $this->getValuesAndFormatsForClient(['rows' => $rows], $viewType)['rows'];
                    }
                } else {
                    $allCount = $this->countByParams($params);

                    if ($allCount > $onPage) {
                        if ($prevLastId) {
                            if ($prevLastId === -1) {
                                $offset = $offset ?? $allCount;
                                if ((explode('/', $this->tableRow['pagination'])[2] ?? '') == 'last') {
                                    $offset = $offset ?? $allCount - ($allCount % $onPage ? $allCount % $onPage : $onPage) + $onPage;
                                }
                            } else {
                                $offset = $offset ?? $this->countByParams(
                                        $params,
                                        $this->orderParamsForLoadRows(true),
                                        $prevLastId
                                    ) - 1;
                            }

                            if ($offset < $onPage) {
                                if ((explode('/', $this->tableRow['pagination'])[2] ?? '') === 'desc') {
                                    $onPage = $offset;
                                }
                                $offset = 0;
                            } else {
                                $offset -= $onPage;
                            }
                            /*$offset -= $onPage;*/
                            if ($offset < 0) {
                                $offset = 0;
                            }
                        } elseif ($lastId === 'last') {
                            $offset = $offset ?? $allCount - ($allCount % $onPage ? $allCount % $onPage : $onPage);
                        } elseif ($lastId === 'desc') {
                            $offset = $offset ?? $allCount - $onPage;
                        } elseif (is_array($lastId) || $lastId > 0) {
                            $offset = $offset ?? $this->countByParams(
                                    $params,
                                    $this->orderParamsForLoadRows(true),
                                    $lastId
                                );
                        }

                        $filteredIds = $this->loadRowsByParams(
                            $params,
                            $this->orderParamsForLoadRows(),
                            $offset,
                            $onPage
                        );
                        $rows = $getRows($filteredIds);
                    } else {
                        $filteredIds = $this->loadRowsByParams($params, $this->orderParamsForLoadRows());
                        $rows = $getRows($filteredIds);
                    }
                    $rows = $this->getValuesAndFormatsForClient(['rows' => $rows], $viewType)['rows'];
                }

                $result = ['rows' => $rows, 'offset' => (int)$offset, 'allCount' => $allCount];
            } else {
                if (!is_null($onlyFields)) {
                    $cropFieldsInRows = function ($rows) use ($onlyFields) {
                        $onlyFields[] = 'id';
                        $onlyFields = array_flip($onlyFields);
                        foreach ($rows as &$row) {
                            $row = array_intersect_key($row, $onlyFields);
                        }
                        unset($row);
                        return $rows;
                    };
                } else {
                    $cropFieldsInRows = function ($rows) {
                        return $rows;
                    };
                }

                $filteredIds = $this->loadRowsByParams($params, $this->orderParamsForLoadRows());
                $rows = $getRows($filteredIds);
                $rows = $this->getValuesAndFormatsForClient(['rows' => $cropFieldsInRows($rows)], $viewType)['rows'];
                $this->sortRowsBydefault($rows);

                $result = ['rows' => $rows, 'offset' => 0, 'allCount' => count($filteredIds)];
            }
            $this->calcLog($Log, 'result', 'done');

            return $result;
        }
    }

    public function sortRowsByDefault(&$rows)
    {
        $tableRow = $this->getTableRow();
        $orderFieldName = $this->getOrderFieldName();

        $fields = $this->getFields();

        if ($tableRow['order_field']
            && $orderFieldName !== 'id'
            && $orderFieldName !== 'n'
            && ($orderField = ($fields[$orderFieldName]))
            && in_array($orderField['type'], ['select', 'tree'])
        ) {
            $sortArray = [];
            foreach ($rows as $row) {
                $sortArray[] = $row[$orderFieldName]['v_'][0] ?? $row[$orderFieldName]['v'];
            }
            array_multisort(
                $sortArray,
                SORT_NATURAL,
                $rows
            );
            if (!empty($tableRow['order_desc'])) {
                $rows = array_reverse($rows);
            }
        }
    }

    abstract public function getLastUpdated($force = false);

    protected function getRemoveForActionDeleteDuplicate($where, $limit)
    {
        $getParams = ['where' => $where, 'field' => 'id'];
        if ((int)$limit === 1) {
            if ($id = $this->getByParams($getParams, 'field')) {
                $remove = [$id];
            } else {
                return false;
            }
        } else {
            $remove = $this->getByParams($getParams, 'list');
        }
        return $remove;
    }

    protected function getModifyForActionSet($params, $where, $limit)
    {
        return $this->prepareModify($params, $where, $limit);
    }

    protected function prepareModify($params, $where, $limit, $clear = false)
    {
        $rowParams = [];
        $pParams = [];
        if ($clear) {
            foreach ($params as $f) {
                if (key_exists($f, $this->fields)) {
                    if ($this->fields[$f]['category'] === 'column') {
                        $rowParams[$f] = null;
                    } else {
                        $pParams[$f] = null;
                    }
                }
            }
        } else {
            foreach ($params as $f => $value) {
                if (key_exists($f, $this->fields)) {
                    if ($this->fields[$f]['category'] === 'column') {
                        $rowParams[$f] = $clear ? null : $value;
                    } else {
                        $pParams[$f] = $clear ? null : $value;
                    }
                }
            }
        }

        $modify = [];

        if (!empty($rowParams)) {
            $getParams = ['where' => $where, 'field' => 'id'];
            if ((int)$limit === 1) {
                if ($id = $this->getByParams($getParams, 'field')) {
                    $return = [$id];
                } else {
                    return false;
                }
            } else {
                $return = $this->getByParams($getParams, 'list');
            }

            foreach ($return as $id) {
                $modify[$id] = $rowParams;
            }
        }
        if (!empty($pParams)) {
            $modify ['params'] = $pParams;
        }

        return $modify;
    }

    public function getModifyForActionSetExtended($params, $where)
    {
        $modify = [];
        $wheres = [];
        $modifys = [];

        $maxCount = 0;
        foreach ($where as $i => $_w) {
            if (is_array($_w['value']) && count($_w['value']) > $maxCount) {
                $maxCount = count($_w['value']);
            }
        }
        foreach ($params as $f => $valueList) {
            if (is_array($valueList) && count($valueList) > $maxCount) {
                $maxCount = count($valueList);
            }
        }


        foreach ($where as $i => $_w) {
            if (is_array($whereList = $_w['value']) && array_key_exists(
                    0,
                    $whereList
                ) && count($whereList) !== $maxCount) {
                throw new errorException('В параметре where необходимо использовать лист по количеству изменяемых строк либо не лист');
            }

            if (is_array($whereList) && array_key_exists(0, $whereList)) {
                foreach ($whereList as $ii => $_wVal) {
                    $wheres[$ii][$i] = ['value' => $_wVal] + $_w;
                }
            } else {
                for ($ii = 0; $ii < $maxCount; $ii++) {
                    $wheres[$ii][$i] = $_w;
                }
            }
        }
        foreach ($params as $f => $valueList) {
            if ($this->fields[$f]['category'] !== 'column') {
                throw new errorException('Функция используется для изменения строчной части таблицы');
            }

            if (is_object($valueList)) {
                if (is_array($valueList->val)) {
                    if (is_array($valueList->val) && array_key_exists(
                            0,
                            $valueList->val
                        ) && count($valueList->val) !== $maxCount) {
                        throw new errorException('В параметре field необходимо использовать лист по количеству изменяемых строк либо не лист');
                    }
                    foreach ($valueList->val as $ii => $val) {
                        $newObj = new FieldModifyItem($valueList->sign, $val, $valueList->percent);
                        $modifys[$ii][$f] = $newObj;
                    }
                    continue;
                }
            }
            if (is_array($valueList) && array_key_exists(
                    0,
                    $valueList
                ) && count($valueList) !== $maxCount) {
                throw new errorException('В параметре field необходимо использовать лист по количеству изменяемых строк либо не лист');
            }

            if (is_array($valueList) && array_key_exists(0, $valueList)) {
                foreach ($valueList as $ii => $_wl) {
                    $modifys[$ii][$f] = $_wl;
                }
            } else {
                for ($ii = 0; $ii < $maxCount; $ii++) {
                    $modifys[$ii][$f] = $valueList;
                }
            }
        }
        foreach ($wheres as $i => $where) {
            $return = $this->getByParams(['where' => $where, 'field' => 'id'], 'list');
            foreach ($return as $id) {
                $modify[$id] = $modifys[$i];
            }
        }

        return $modify;
    }

    protected function getModifyForActionClear($fields, $where, $limit)
    {
        return $this->prepareModify($fields, $where, $limit, true);
    }


    protected function checkTableUpdated($tableData = null)
    {
        if (is_null($tableData)) {
            return;
        }

        $updated = $this->updated;
        if ($this->tableRow['actual'] === 'strong' && $tableData && ($tableData['updated'] ?? null) && json_decode(
                $updated,
                true
            ) != $tableData['updated']
        ) {
            throw new errorException('Таблица была изменена. Обновите таблицу для проведения изменений');
        }
    }

    protected function getFieldsForAction($action, $fieldCategory)
    {
        $key = $action . ':' . $fieldCategory;
        if (!array_key_exists($key, $this->cacheForActionFields)) {
            $fieldsForAction = [];
            foreach ($this->sortedFields[$fieldCategory] ?? [] as $field) {
                if (!empty($field['CodeActionOn' . $action])) {
                    $fieldsForAction[] = $field;
                }
            }

            $this->cacheForActionFields[$key] = $fieldsForAction;
        }
        return $this->cacheForActionFields[$key];
    }

    public function isJsonTable()
    {
        return is_a($this, JsonTables::class);
    }

    abstract protected function _copyTableData(&$table, $settings);

    protected function _getIntervals($ids)
    {
        $ids = str_replace(' ', '', $ids);
        $intervals = [];
        foreach (explode(',', $ids) as $interval) {
            if ($interval === '') {
                continue;
            } elseif (preg_match('/^\d+$/', $interval)) {
                $intervals[] = [$interval, $interval];
            } elseif (preg_match('/^(\d+)-(\d+)$/', $interval, $matches)) {
                $intervals[] = [$matches[1], $matches[2]];
            } else {
                throw new errorException('Некорректный интервал [[' . $interval . ']]');
            }
        }
        return $intervals;
    }

    protected function issetActiveFilters($channel)
    {
        $isActiveFilter = function ($field) {
            if (!is_null($this->tbl['params'][$field['name']] ?? null)) {
                $filterVal = $this->tbl['params'][$field['name']];
                if ($field['type'] === 'select') {
                    if (in_array('*ALL*', (array)$filterVal)
                        || in_array('*NONE*', (array)$filterVal)
                    ) {
                        return false;
                    }
                    return true;
                } elseif ($filterVal !== '') {
                    return true;
                }
            }
        };

        switch ($channel) {
            case 'web':
            case 'edit':
                foreach ($this->fields as $field) {
                    if ($field['category'] === 'filter' && $field['showInWeb'] === true) {
                        return $isActiveFilter($field);
                    }
                }
                break;
            case 'xml':
                foreach ($this->fields as $field) {
                    if ($field['category'] === 'filter' && $field['showInXml'] === true) {
                        return $isActiveFilter($field);
                    }
                }
                break;
            default:
                return [];
        }
    }

    /**
     * @return string
     */
    public function getOrderFieldName(): string
    {
        return $this->orderFieldName;
    }

    abstract protected function reCalculateRows(
        $calculate,
        $channel,
        $isCheck,
        $modifyCalculated,
        $isTableAdding,
        $remove,
        $restore,
        $add,
        $modify,
        $setValuesToDefaults,
        $setValuesToPinned,
        $duplicate,
        $reorder,
        $addAfter,
        $addWithId
    );

    /**
     * @param string $property visible|insertable|editable|filterable
     * @param string $channel web|xml
     * @param $field
     * @return mixed
     * @throws errorException
     */
    public function isField($property, $channel, $field)
    {
        $User = $this->Mir->getUser();
        $userRoles = $User->getRoles();
        $isUserCreatorOrInRoles = function ($roles) use ($User, $userRoles) {
            return ($User->isCreator() || empty($roles) || array_intersect($roles, $userRoles));
        };

        switch ($channel) {
            case 'web':
                $visible = $field['showInWeb'] && $isUserCreatorOrInRoles($field['webRoles'] ?? []);
                switch ($property) {
                    case 'visible':
                        return $visible;
                    case 'filterable':
                        return $field['showInWeb'];
                    case 'editFilterByUser':
                        return $field['showInWeb'] && ($field['editable'] ?? false) && $isUserCreatorOrInRoles($field['webRoles'] ?? []) && $isUserCreatorOrInRoles($field['editRoles'] ?? []);
                    case 'insertable':
                        return $visible && ($field['insertable'] ?? false) && $isUserCreatorOrInRoles($field['addRoles'] ?? []);
                    case 'editable':
                        /*Для фильтра ограничения видимости по ролям не отключают редактирование*/
                        if ($field['category'] === 'filter') {
                            return ($field['showInWeb'] ?? false) && ($field['editable'] ?? false) && $isUserCreatorOrInRoles($field['editRoles'] ?? []);
                        }
                        return $visible && ($field['editable'] ?? false) && $isUserCreatorOrInRoles($field['editRoles'] ?? []);
                }
                break;
            case 'xml':
                $visible = ($field['showInXml'] ?? null) && $isUserCreatorOrInRoles($field['xmlRoles'] ?? []);

                switch ($property) {
                    case 'visible':
                        return $visible;
                    case 'insertable':
                        return $visible && $field['apiInsertable'];
                    case 'filterable':
                        return $field['showInXml'];
                    case 'editable':
                        return $visible && $field['apiEditable'] && $isUserCreatorOrInRoles($field['xmlEditRoles'] ?? []);
                    default:
                        throw new errorException('In channel ' . $channel . ' not supported action ' . $property);
                }
                break;
            case 'inner':
                switch ($property) {
                    case 'filterable':
                        return false;
                    default:
                        true;
                }
                break;
            default:
                throw new errorException('Channel ' . $channel . ' not supported in function isField');
        }
        return false;
    }
}
