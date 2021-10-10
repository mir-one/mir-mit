<?php


namespace mir\common\configs;

use Exception;
use mir\common\Model;
use mir\models\CalcsTableCycleVersion;
use mir\models\CalcsTablesVersions;
use mir\models\NonProjectCalcs;
use mir\models\Table;
use mir\models\TablesCalcsConnects;
use mir\models\TablesFields;
use mir\models\TmpTables;
use mir\models\Tree;
use mir\models\TreeV;
use mir\models\UserV;

trait TablesModelsTrait
{
    protected static $modelsConnector = [
        'users' => \mir\models\User::class
        , 'users__v' => UserV::class
        , 'tree' => Tree::class
        , 'tree__v' => TreeV::class
        , 'tables_fields' => TablesFields::class
        , 'tables' => Table::class
        , 'calcstable_cycle_version' => CalcsTableCycleVersion::class
        , 'tables_nonproject_calcs' => NonProjectCalcs::class
        , 'tables_calcs_connects' => TablesCalcsConnects::class
        , 'calcstable_versions' => CalcsTablesVersions::class
        , '_tmp_tables' => TmpTables::class
    ];
    private $tableRowsById = [];
    private $tableRowsByName = [];

    /* Инициализированные модели */
    protected $models = [];

    public function getModel($table, $idField = null, $isService = null): Model
    {
        $keyStr = $table . ($isService ? '!!!' : '');

        if (key_exists($keyStr, $this->models)) {
            return $this->models[$keyStr];
        }
        $className = $this->getModelClassName($table);

        return $this->models[$keyStr] = new $className(
            $this->getSql(),
            $table,
            $idField,
            $isService
        );
    }

    public static function getTableNameByModel($className)
    {
        $tableName = array_flip(static::$modelsConnector)[$className] ?? null;

        if (!$tableName) {
            if ($className === Model::class) {
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                /*TODO после тестирования и проверок - обрабатывать по-другому*/
                throw new Exception('Ошибка модель вызывается по-старому');
            }
            throw new Exception('Модель ' . $className . ' не подключена к коннектору');
        }
        return $tableName;
    }

    /* Сюда можно будет поставить общую систему кешей */
    /**
     * @param int|string $table
     * @return array|null
     */
    public function getTableRow($table, $force = false)
    {
        if (is_int($table) || ctype_digit($table)) {
            if (!$force && key_exists($table, $this->tableRowsById)) {
                return $this->tableRowsById[$table];
            }

            $where = ['id' => $table];
        } else {
            if (!$force && key_exists($table, $this->tableRowsByName)) {
                return $this->tableRowsByName[$table];
            }
            $where = ['name' => $table];
        }
        $Model = $this->getModel('tables');
        $stmt=$Model->executePrepared(true, $where);

        if ($row = $stmt->fetch()) {
            foreach ($row as $k => &$v) {
                if (!in_array($k, Model::serviceFields)) {
                    if (is_array($v)) {
                        debug_print_backtrace();
                    }
                    if (!array_key_exists('v', json_decode($v, true))) {
                        var_dump($row, $k);
                        die;
                    }
                    $v = json_decode($v, true)['v'];
                }
            }
            unset($v);

            $this->tableRowsByName[$row['name']] = $row;
            $this->tableRowsById[$row['id']] = $row;
        }
        return $row;
    }

    public function getNamedModel($className, $isService)
    {
        return $this->getModel(static::getTableNameByModel($className), null, $isService);
    }

    protected function getModelClassName($table)
    {
        if (empty(static::$modelsConnector[$table])) {
            $className = Model::class;
        } else {
            $className = static::$modelsConnector[$table];
        }
        return $className;
    }

    public function clearRowsCache()
    {
        $this->tableRowsById=[];
        $this->tableRowsByName=[];
    }
}
