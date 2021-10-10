<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 27.03.17
 * Time: 15:44
 */

namespace mir\common\calculates;

use mir\common\criticalErrorException;
use mir\common\errorException;
use mir\common\Field;
use mir\common\FieldModifyItem;
use mir\common\Formats;
use mir\common\Model;
use mir\common\sql\SqlException;
use mir\common\Json\MirJson;
use mir\fieldTypes\File;
use mir\models\TablesFields;
use mir\tableTypes\aTable;
use mir\tableTypes\calcsTable;
use mir\tableTypes\RealTables;

class Calculate
{
    protected static $codes;
    protected static $initCodes = [];

    protected $startSections;

    protected $cachedCodes = [];
    protected $oldRow;
    protected $oldTbl;
    protected $newVal;
    protected $row;
    protected $tbl;
    protected $code;
    protected $dectimalPlaces;
    protected $varName;
    protected $log;
    protected $error;
    protected $varData;
    protected $newLog;
    protected $newLogParent;
    protected $whileIterators = [];
    /**
     * @var aTable
     */
    protected $Table;
    protected $vars;
    protected $fixedCodeNames = [];
    protected $fixedCodeVars = [];
    protected $CodeLineParams = [];
    protected $CodeStrings = [];
    /**
     * @var array
     */
    protected $CodeLineCatches;


    public function __construct($code)
    {
        if (!is_array($code)) {
            if (!array_key_exists($code, static::$initCodes)) {
                static::$initCodes[$code] = static::parseMirCode($code);
            }
            $code = static::$initCodes[$code];
        }

        $this->fixedCodeNames = $code['==fixes=='] ?? [];
        $this->CodeStrings = $code['==strings=='] ?? [];
        $this->CodeLineParams = $code['==lineParams=='] ?? [];
        $this->CodeLineCatches = $code['==catches=='] ?? [];


        unset($code['==fixes==']);
        unset($code['==strings==']);
        unset($code['==lineParams==']);

        $this->code = $code;
        $this->formStartSections();
    }

    protected function formStartSections()
    {
        if (key_exists('=', $this->code)) {
            $this->startSections = ['=' => $this->code['=']];
            unset($this->code['=']);
        }
    }

    public static function parseMirCode($code, $table_name = null)
    {
        $c = [];
        $fixes = [];
        $catches = [];
        $strings = [];
        $lineParams = [];

        $tableParams = [];

        foreach (preg_split('/[\r\n]+/', trim($code)) as $row) {
            $row = trim($row);
            /*Убрать комментарии*/
            if (substr($row, 0, 2) === '//') {
                continue;
            }
            /*Разбираем код построчно*/
            if (preg_match('/^([a-z0-9]*=\s*|~?[a-zA-Z0-9_]+)\s*(?<catch>[a-zA-Z0-9_]*)\s*:(.*)$/', $row, $matches)) {
                $lineName = trim($matches['1']);
                if (substr($lineName, 0, 1) === '~') {
                    $lineName = substr($lineName, 1);
                    $fixes[] = $lineName;
                }

                if (substr($lineName, -1, 1) === '=' && $matches['catch']) {
                    $catch = $matches['catch'];
                    $catches [$lineName] = $catch;
                }

                $line = trim($matches[3]);
                /*Используемые параметры*/
                if ($table_name) {
                    if (preg_match_all('/\(.*?table:\s*\'([a-z0-9_]+)\'.*?\)/', $line, $matches)) {
                        foreach ($matches[1] as $i => $t_name) {
                            if (preg_match_all(
                                '/(field|where|order|sfield|bfield|tfield|preview|parent|section|table|filter):\s*\'([a-z0-9_]+)\'/',
                                $matches[0][$i],
                                $mches
                            )) {
                                foreach ($mches[2] as $field) {
                                    $tableParams[$t_name][$field] = 1;
                                }
                            }
                        }
                    }
                    if (preg_match_all('/\(.*?table:\s*\$#ntn.*?\)/', $line, $matches)) {
                        foreach ($matches[0] as $i => $t_name) {
                            if (preg_match_all('/(field|where|order):\s*\'([a-z0-9_]+)\'/', $matches[0][$i], $mches)) {
                                foreach ($mches[2] as $field) {
                                    $tableParams[$table_name][$field] = 1;
                                }
                            }
                        }
                    }
                }

                $replace_line_params = function ($line) use (&$lineParams) {
                    return preg_replace_callback(
                        '/{([^}]+)}/',
                        function ($matches) use (&$lineParams) {
                            if ($matches[1] === "") {
                                return '{}';
                            }
                            $Num = count($lineParams);
                            $lineParams[] = $matches[1];
                            return '{' . $Num . '}';
                        },
                        $line
                    );
                };
                $replace_strings = function ($line) use (&$strings, &$replace_line_params, &$replace_strings) {
                    return preg_replace_callback(
                        '/(?|(math|json|str|cond)`([^`]*)`|(")([^"]*)"|(\')([^\']*)\')/',
                        function ($matches) use (&$strings, &$replace_line_params, &$replace_strings) {
                            if ($matches[1] === "") {
                                return '""';
                            }
                            switch ($matches[1]) {
                                case 'json':
                                    if (!json_decode($matches[2]) && json_last_error()) {
                                        $matches[2] = $replace_strings($matches[2]);
                                    }
                                    break;
                                case 'math':
                                case 'str':
                                case 'cond':
                                    $matches[2] = $replace_strings($matches[2]);
                                    $matches[2] = $replace_line_params($matches[2]);
                                    break;
                            }
                            $stringNum = count($strings);
                            $strings[] = $matches[1] . $matches[2];
                            return '"' . $stringNum . '"';
                        },
                        $line
                    );
                };


                $line = $replace_strings($line);
                $line = str_replace(' ', '', $line);

                if ($table_name && preg_match_all('/(.?)#([a-z_0-9]+)/', $line, $matches)) {
                    foreach ($matches[2] as $i => $m) {
                        if ($matches[1][$i] !== '$') {
                            $tableParams[$table_name][$m] = 1;
                        }
                    }
                }

                $line = $replace_line_params($line);
                $c[$lineName] = $line;
            }
        }


        if ($fixes) {
            $c['==fixes=='] = $fixes;
        }
        if ($strings) {
            $c['==strings=='] = $strings;
        }
        if ($lineParams) {
            $c['==lineParams=='] = $lineParams;
        }
        if ($tableParams) {
            $c['==usedFields=='] = $tableParams;
        }
        if ($catches) {
            $c['==catches=='] = $catches;
        }

        return $c;
    }

    protected static function __compare_normalize($n)
    {
        switch (gettype($n)) {
            case 'NULL':
                return '';
            case 'boolean':
                return $n ? 'true' : 'false';
            case 'integer':
            case 'double':
                return strval($n);
            case 'array':
                static::__compare_array_normalize($n);
                return $n;
        }
//        if ($n === "0" || $n === 0) {
//            return "000000";
//        }
        return $n;
    }

    protected static function __compare_array_normalize(&$n)
    {
        ksort($n);
        foreach ($n as &$nItem) {
            $nItem = static::__compare_normalize($nItem);
        }
        unset($nItem);
    }

    protected static function _compare_n_array($operator, $n, array $n2, $key = null, $isTopLevel = false)
    {
        switch ($operator) {
            case '!==':
                $r = true;
                break;
            case '==':
                $r = false;
                break;
            case '=':
                if (count($n2) === 0) {
                    if ($isTopLevel && (($n ?? "") === "")) {
                        $r = true;
                    } else {
                        $r = false;
                    }
                } else {
                    $r = false;
                    $n = static::__compare_normalize($n);

                    if (is_null($key)) {
                        foreach ($n2 as $nItem) {
                            if ($n === static::__compare_normalize($nItem)) {
                                $r = true;
                                break;
                            }
                        }
                    } else {
                        $key = strval($key);
                        foreach ($n2 as $nKey => $nItem) {
                            if (strval($nKey) === $key && $n === static::__compare_normalize($nItem)) {
                                $r = true;
                                break;
                            }
                        }
                    }
                }
                break;
            case '!=':
                if (count($n2) === 0) {
                    if ($isTopLevel && (($n ?? "") === "")) {
                        $r = true;
                    } else {
                        $r = false;
                    }
                } else {
                    $r = false;
                    $n = static::__compare_normalize($n);

                    if (is_null($key)) {
                        foreach ($n2 as $nItem) {
                            if ($n === static::__compare_normalize($nItem)) {
                                $r = true;
                                break;
                            }
                        }
                    } else {
                        $key = strval($key);
                        foreach ($n2 as $nKey => $nItem) {
                            if (strval($nKey) === $key && $n === static::__compare_normalize($nItem)) {
                                $r = true;
                                break;
                            }
                        }
                    }
                }
                $r = !$r;
                break;
            default:
                throw new errorException('Для сравнения листов только =, == и !=');
        }
        return $r;
    }

    public static function compare($operator, $n, $n2)
    {
        $nIsRow = $n2IsRow = false;
        if ($nIsArray = is_array($n)) {
            if (count($n) > 0 && (array_keys($n) !== range(0, count($n) - 1))) {
                $nIsRow = true;
                $nIsArray = false;
            } else {
                $nIsRow = false;
            }
        }

        if ($n2IsArray = is_array($n2)) {
            if (count($n2) > 0 && (array_keys($n2) !== range(0, count($n2) - 1))) {
                $n2IsRow = true;
                $n2IsArray = false;
            } else {
                $n2IsRow = false;
            }
        }


        if (($nIsArray && $n2IsArray) || ($n2IsRow && $nIsRow)) {
            switch ($operator) {
                case '!==':
                    $r = false;
                    if (count($n) === count($n2)) {
                        static::__compare_array_normalize($n);
                        static::__compare_array_normalize($n2);
                        $r = $n === $n2;
                    }
                    $r = !$r;
                    break;
                case '==':
                    $r = false;
                    if (count($n) === count($n2)) {
                        static::__compare_array_normalize($n);
                        static::__compare_array_normalize($n2);
                        $r = $n === $n2;
                    }
                    break;
                case '=':
                    if (count($n) === 0 && count($n2) === 0) {
                        $r = true;
                    } else {
                        $r = false;

                        foreach ($n as $key => $nItem) {
                            if (static::_compare_n_array('=', $nItem, $n2, $nIsRow ? $key : null)) {
                                $r = true;
                                break 2;
                            }
                        }
                    }
                    break;
                case '!=':
                    if (count($n) === 0 && count($n2) === 0) {
                        $r = false;
                    } else {
                        $r = true;

                        foreach ($n as $key => $nItem) {
                            if (static::_compare_n_array('=', $nItem, $n2, $nIsRow ? $key : null)) {
                                $r = false;
                                break 2;
                            }
                        }
                    }
                    break;
                default:
                    throw new errorException('Для сравнения листов только =, == и !=, !==, не ' . $operator);
            }
        } elseif (is_numeric($n) && is_numeric($n2)) {
            switch ($n <=> $n2) {
                case 0:
                    $r = in_array($operator, ['>=', '<=', '=', '==']) ? true : false;
                    break;
                case 1:
                    $r = in_array($operator, ['>=', '>', '!=', '!==']) ? true : false;
                    break;
                default:
                    $r = in_array($operator, ['<=', '<', '!=', '!==']) ? true : false;
            }
        } elseif ($n2IsArray) {
            return static::_compare_n_array($operator, $n, $n2, null, true);
        } elseif ($nIsArray) {
            return static::_compare_n_array($operator, $n2, $n, null, true);
        } else {
            switch (static::__compare_normalize($n) <=> static::__compare_normalize($n2)) {
                case 0:
                    $r = in_array($operator, ['>=', '<=', '=', '==']) ? true : false;
                    break;
                case 1:
                    $r = in_array($operator, ['>=', '>', '!=', '!==']) ? true : false;
                    break;
                default:
                    $r = in_array($operator, ['<=', '<', '!=', '!==']) ? true : false;
            }
        }

        return $r;
    }

    public static function getDateObject($dateFromParams)
    {
        $date = null;
        if (is_array($dateFromParams)) {
            throw new errorException('Получен список вместо даты');
        }
        $dateFromParams = strval($dateFromParams);
        if ($dateFromParams !== "") {
            foreach (['Y-m-d', 'd.m.y', 'd.m.Y', 'Y-m-d H:i', 'd.m.y H:i', 'd.m.Y H:i', 'Y-m-d H:i:s'] as $format) {
                if ($date = date_create_from_format($format, $dateFromParams)) {
                    if (!strpos($format, 'H')) {
                        $date->setTime(0, 0);
                    }
                    return $date;
                }
            }
        }
        return null;
    }

    protected function getCodes($stringIN)
    {
        $cacheString =& self::$codes;

        /*if (strpos($stringIN, '"') !== false || strpos($stringIN, '{') !== false) {
            $cacheString = &$this->cachedCodes;
        }*/

        if (empty($cacheString[$stringIN])) {
            $i = 0;
            $done = 1;
            $code = [];
            $string = $stringIN;

            while ($done && ($i < 100) && $string !== "") {
                $done = 0;
                $i++;
                $string = preg_replace_callback(
                    '`(?<func>(?<func_name>[a-zA-Z]{2,}\d*)*\((?<func_params>[^)]*)\))' . //func,func_name,func_params
                    '|(?<num>\-?[\d.,]+\%?)' .                      //num
                    '|(?<operator>\^|\+|\-|\*|/)' .       //operator
                    '|(?<string>"[^"]*")' .            //string
                    '|(?<comparison>!==|==|>=|<=|>|<|=|!=)' .       //comparison
                    '|(?<bool>false|true)' .   //10
                    '|(?<param>(?<param_name>(?:\$@|@\$|\$\$|\$\#?|\#(?i:(?:old|s|h|c|l)\.)?\$?)(?:[a-zA-Z0-9_]+(?:{[^}]*})?))(?<param_items>(?:\[\[?\$?\#?[a-zA-Z0-9_"]+\]?\])*))' . //param,param_name,param_items
                    '|(?<dog>@(?<dog_table>[a-zA-Z0-9_]{3,})\.(?<dog_field>[a-zA-Z0-9_]{2,})(?<dog_items>(?:\[\[?\$?\#?[a-zA-Z0-9_"]+\]?\])*))`',
                    //dog,dog_table, dog_field,dog_items

                    function ($matches) use (&$done, &$code) {
                        if ($matches[0] !== '') {
                            if ($matches['func'] && ($funcName = $matches['func_name'])) {
                                $code[] = [
                                    'type' => 'func',
                                    'func' => $funcName,
                                    'params' => $matches['func_params']
                                ];
                            } elseif ($matches['num'] !== '') {
                                $number = $matches['num'];
                                $cn = [
                                    'type' => 'string',
                                    'string' => $number
                                ];
                                if (substr($number, -1, 1) === '%') {
                                    $cn['percent'] = true;
                                    $cn['string'] = trim(substr($number, 0, -1));
                                } elseif (is_numeric($cn['string'])) {
                                    $cn['string'] = ctype_digit($cn['string']) ? (int)$cn['string'] : (float)$cn['string'];
                                }
                                //$code[] = $number;
                                $code[] = $cn;
                            } elseif ($operator = $matches['operator']) {
                                $code[] = [
                                    'type' => 'operator',
                                    'operator' => $operator
                                ];
                            } elseif ($param = $matches['string']) {
                                if (strlen(substr($param, 1, -1)) > 0) {
                                    $code[] = [
                                        'type' => 'stringParam',
                                        'string' => substr($param, 1, -1)
                                    ];
                                } else {
                                    $code[] = [
                                        'type' => 'string',
                                        'string' => ""
                                    ];
                                }
                            } elseif ($comparison = $matches['comparison']) {
                                if (array_key_exists('comparison', $code)) {
                                    throw new errorException('Оператор сравнения может быть только один в строке' . print_r(
                                        $matches,
                                        1
                                    ));
                                }

                                $code['comparison'] = $comparison;
                            } elseif ($param = $matches['bool']) {
                                $code[] = [
                                    'type' => 'boolean',
                                    'boolean' => $param
                                ];
                            } elseif ($param = $matches['param']) {
                                $code[] = [
                                    'type' => 'param',
                                    'param' => $matches['param_name'],
                                    'items' => $matches['param_items']
                                ];
                            } elseif ($param = $matches['dog']) {
                                $code[] = [
                                    'type' => 'param',
                                    'param' => $param,
                                    'table' => $matches['dog_table'],
                                    'field' => $matches['dog_field'],
                                    'items' => $matches['dog_items']
                                ];
                            }


                            $done = 1;
                        }
                        return '';
                    },
                    $string,
                    1
                );

                $string = trim($string);
                if ($done === 0 && $string) {
                    throw new errorException('Ошибка кода [[' . $string . ']] - не подходит по формату');
                }
            }
            $cacheString[$stringIN] = $code;
        }

        return $cacheString[$stringIN];
    }

    public function getLogVar()
    {
        return $this->newLog;
    }

    public function getError()
    {
        return $this->error;
    }

    protected function funcXmlExtract($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if ($xml = @simplexml_load_string($params['xml'])) {
                $getData = function (\SimpleXMLElement $xml) use (&$getData, $params) {
                    $children = [];
                    foreach ($xml->attributes() as $k => $attr) {
                        $children[$params['attrpref'] . $k] = (string)$attr;
                    }
                    foreach ($xml->getNamespaces() as $pref => $namespace) {
                        foreach ($xml->children($namespace) as $k => $child) {
                            $children[$pref . ':' . $k][] = $getData($child);
                        }
                    }
                    foreach ($xml->children() as $k => $child) {
                        $children[$k][] = $getData($child);
                    }
                    if ((string)$xml) {
                        $children[$params['textname']] = trim((string)$xml);
                    }
                    return $children;
                };

                return [$xml->getName() => $getData($xml)];
            } else {
                throw new errorException('Ошибка формата XML');
            }
        } else {
            throw new errorException('Ошибка параметров функции');
        }
    }

    public function exec($fieldData, $newVal, $oldRow, $row, $oldTbl, $tbl, aTable $table, $vars = [])
    {
        $this->error = null;

        $this->vars = $vars;
        $this->fixedCodeVars = [];

        $this->whileIterators = [];
        $this->setEnvironmentVars($fieldData, $newVal, $oldRow, $row, $oldTbl, $tbl, $table);
        $this->varName = $fieldData['name'];

        $this->newLog = [];
        $this->newLogParent = &$this->newLog;


        $params = ['calc' => static::class, 'itemId' => $row['id'] ?? $oldRow['id'] ?? null];
        if ($this->varName[0] === 'C') {
            $params['name'] = $this->varName;
        } else {
            $params['field'] = $this->varName;
        }

        if (!key_exists('cType', $table->getCalculateLog()->getParams())) {
            $Log = $table->calcLog($params);
        }

        try {
            if (empty($this->startSections)) {
                throw new errorException('Ошибка кода - нет стартовой секции ');
            }

            foreach ($this->startSections as $sectionName => $section) {
                try {
                    $r = $this->execSubCode($section, $sectionName);
                } catch (\Exception $exception) {
                    if (key_exists($sectionName, $this->CodeLineCatches)) {
                        if (key_exists($this->CodeLineCatches[$sectionName], $this->code)) {
                            $this->vars['exception'] = $exception->getMessage();
                            $r = $this->execSubCode(
                                $this->code[$this->CodeLineCatches[$sectionName]],
                                $this->CodeLineCatches[$sectionName]
                            );
                        } else {
                            throw new errorException('Строка catch кода '.$this->code[$this->CodeLineCatches[$sectionName]].' не найдена.');
                        }
                    } else {
                        throw $exception;
                    }
                }
            }
            if (!empty($Log)) {
                $table->calcLog($Log, 'result', $r);
            }
        } catch (errorException $e) {
            $this->newLog['text'] = ($this->newLog['text'] ?? '') . 'ОШБК!';
            $this->newLog['children'][] = ['type' => 'error', 'text' => $e->getMessage()];
            $this->error = $e->getMessage();

            if (!empty($Log)) {
                $table->calcLog($Log, 'error', $this->error);
            }
            if (get_called_class() !== Calculate::class) {
                throw $e;
            }
        } catch (\Exception $e) {
            $this->newLog['text'] = ($this->newLog['text'] ?? '') . 'ОШБК!';
            if (is_a($e, SqlException::class)) {
                $this->newLog['children'][] =
                    ['type' => 'error', 'text' => 'Ошибка базы данных при обработке кода [[' . $e->getMessage() . ']]'];
                $this->error = 'Ошибка базы данных при обработке кода [[' . $e->getMessage() . ']]';
            } else {
                $this->newLog['children'][] =
                    ['type' => 'error', 'text' => 'Критическая ошибка при обработке кода [[' . $e->getMessage() . ']]'];
                $this->error = 'Критическая ошибка при обработке кода [[' . $e->getMessage() . ']]';
            }
            if (!empty($Log)) {
                $table->calcLog($Log, 'error', $this->error);
            }

            throw $e;
        }
        if ($this->error) {
            $this->error .= ' (поле [[' . $this->varName . ']] таблицы [[' . $this->Table->getTableRow()['name'] . ']])';
        }

        return $r ?? $this->error;
    }

    protected function funcLinkToDataTable($params)
    {
        $params = $this->getParamsArray($params);

        if (!is_a($this, CalculateAction::class) && empty($params['hide'])) {
            errorException::criticalException('Нельзя использовать linktodataTable вне actionCode без hide:true');
        }

        $tableRow = $this->__checkTableIdOrName($params['table'], 'table');

        $tmp = $this->Table->getMir()->getTable($tableRow);
        $tmp->addData(['tbl' => $params['data'] ?? [], 'params' => ($params['params'] ?? [])]);
        if (empty($params['hide'])) {
            if (empty($params['width'])) {
                $width = 130;
                foreach ($tmp->getVisibleFields('web', true)['column'] as $field) {
                    $width += $field['width'];
                }
                if ($width > 1200) {
                    $width = 1200;
                }
            } else {
                $width = $params['width'];
            }
            $table = [
                'title' => $params['title'] ?? $tableRow['title'],
                'table_id' => $tableRow['id'],
                'sess_hash' => $tmp->getTableRow()['sess_hash'],
                'width' => $width,
                'height' => $params['height'] ?? '80vh'
            ];
            $this->Table->getMir()->addToInterfaceDatas(
                'table',
                $table,
                $params['refresh'] ?? false,
                ['header' => $params['header'] ?? true,
                    'footer' => $params['footer'] ?? true]
            );
        }
        return $tmp->getTableRow()['sess_hash'];
    }

    protected function funcExec($params)
    {
        if ($params = $this->getParamsArray($params, ['var'], ['var'])) {
            $code = $params['code'] ?? $params['kod'] ?? '';
            if (!empty($code)) {
                if (preg_match('/^[a-z_0-9]{3,}$/', $code) && key_exists($code, $this->Table->getFields())) {
                    $code = $this->Table->getFields()[$code]['code'] ?? '';
                }

                $CA = new Calculate($code);
                try {
                    $Vars = [];
                    foreach ($params['var'] ?? [] as $v) {
                        $Vars = array_merge($Vars, $this->getExecParamVal($v));
                    }
                    $r = $CA->exec(
                        $this->varData,
                        $this->newVal,
                        $this->oldRow,
                        $this->row,
                        $this->oldTbl,
                        $this->tbl,
                        $this->Table,
                        $Vars
                    );

                    $this->newLogParent['children'][] = $CA->getLogVar();
                    return $r;
                } catch (errorException $e) {
                    $this->newLogParent['children'][] = $CA->getLogVar();
                    throw $e;
                }
            }
        }
    }

    protected function funcNowSchema()
    {
        return $this->Table->getMir()->getConfig()->getSchema();
    }

    protected function setEnvironmentVars($varData, $newVal, $oldRow, $row, $oldTbl, $tbl, $table)
    {
        // Log::calcs($newVal, $row, $tbl, $dectimalPlaces);

        $this->varName = $varData['name'];
        $this->varData = $varData;
        $this->newVal = $newVal;
        $this->oldRow = $oldRow;
        $this->row = $row;
        $this->oldTbl = $oldTbl;
        $this->tbl = $tbl;
        $this->Table = $table;
    }

    protected function operatorExec($operator, $left, $right)
    {
        if ($left != 0) {
            $this->__checkNumericParam($left, 'левый элемент', $operator);
        }
        if ($right != 0) {
            $this->__checkNumericParam($right, 'правый элемент', $operator);
        }
        $left = floatval($left);
        $right = floatval($right);

        switch ($operator) {
            case '+':
                $result = $left + $right;
                break;
            case '-':
                $result = $left - $right;
                break;
            case '*':
                $result = $left * $right;
                break;
            case '^':
                $result = pow($left, $right);
                break;
            case '/':
                if ((float)$right === 0) {
                    throw new errorException('Деление на ноль');
                }
                $result = $left / $right;
                break;
            default:
                throw new errorException('Неизвестный оператор [[' . $operator . ']]');
        }

        $result = (float)(string)round($result, 10);
        /* $this->addInLogVar('Вычисление сравнения',
             ['left' => $left, 'operator' => $operator, 'right' => $right, 'result' => $result]);*/

        return $result;
    }

    public function getReadCodeForLog($code)
    {
        $code = preg_replace_callback(
            '/{(.*?)}/',
            function ($m) {
                if ($m[1] === "") {
                    return '{}';
                }
                return '{' . $this->CodeLineParams[$m[1]] . '}';
            },
            $code
        );
        $code = preg_replace_callback(
            '/"(.*?)"/',
            function ($m) {
                if ($m[1] === "") {
                    return '""';
                }
                $qoute = $this->CodeStrings[$m[1]][0];
                switch ($qoute) {
                    case '"':
                    case "'":
                        return $qoute . substr($this->CodeStrings[$m[1]], 1) . $qoute;
                        break;
                    default:
                        $back_replace_strings = function ($str) {
                            return preg_replace_callback(
                                '/"(\d+)"/',
                                function ($matches) {
                                    if ($matches[1] === "") {
                                        return '""';
                                    }
                                    return '"' . $this->CodeStrings[$matches[1]] . '"';
                                },
                                $str
                            );
                        };

                        $replaced = $back_replace_strings($this->CodeStrings[$m[1]]);
                        return substr($this->CodeStrings[$m[1]], 0, 4) . '`' . substr(
                            $replaced,
                            4
                        ) . '`';
                }
            },
            $code
        );
        return $code;
    }

    protected function inVarsApply($inVars)
    {
        $pastVals = [];
        foreach ($inVars as $k => $v) {
            if (array_key_exists($k, $this->vars)) {
                $pastVals[$k] = [true, $this->vars[$k]];
            } else {
                $pastVals[$k] = [false];
            }
            $this->vars[$k] = $v;
        }
        return $pastVals;
    }

    protected function inVarsRevert($pastVals)
    {
        foreach ($pastVals as $k => $v) {
            if (!$v[0]) {
                unset($this->vars[$k]);
            } else {
                $this->vars[$k] = $v[1];
            }
        }
    }

    protected function execSubCode($code, $codeName, $notLoging = false, $inVars = [])
    {
        $Log = $this->Table->calcLog(['name' => $codeName, 'code' => function () use ($code) {
            return $this->getReadCodeForLog($code);
        }]);

        try {
            $pastVals = $this->inVarsApply($inVars);

            $codes = $this->getCodes($code);

            $result = null;
            $result2 = null;

            $res =& $result;
            $operator = null;
            $comparison = null;

            foreach ($codes as $k => $r) {
                $rTmp = null;
                if ($k === 'comparison') {
                    $comparison = $r;
                    $res =& $result2;
                    continue;
                } elseif (is_string($r)) {
                    $rTmp = $r;
                } else {
                    switch ($r['type']) {
                        case 'spec_math':
                            $rTmp = $this->getMathFromString($r['string']);
                            break;
                        case 'operator':
                            $operator = $r['operator'];
                            continue 2;
                            break;
                        case 'func':
                            $func = $r['func'];

                            if (strpos($func, 'ext') === 0) {
                                $func = $this->Table->getMir()->getConfig()->getCalculateExtensionFunction($func);
                                try {
                                    $rTmp = $func->call($this, $r['params'], $rTmp);
                                } catch (errorException $e) {
                                    $e->addPath('Функция [[' . $func . ']]');
                                    throw $e;
                                }
                            } else {
                                $funcName = 'func' . $func;
                                if (!is_callable([$this, $funcName])) {
                                    throw new errorException('Функция [[' . $func . ']] не найдена');
                                }

                                try {
                                    $rTmp = $this->$funcName($r['params'], $rTmp);
                                } catch (errorException $e) {
                                    $e->addPath('Функция [[' . $func . ']]');
                                    throw $e;
                                }
                            }


                            break;
                        case 'param':
                            $rTmp = $this->getParam($r['param'], $r);
                            break;
                        case 'stringParam':
                            $spec = substr($this->CodeStrings[$r['string']], 0, 4);

                            switch ($spec) {
                                case 'math':
                                    $rTmp = $this->getMathFromString(substr($this->CodeStrings[$r['string']], 4));
                                    break;
                                case 'json':
                                    $rTmp = $this->parseMirJson(substr($this->CodeStrings[$r['string']], 4));
                                    break;
                                case 'cond':
                                    $rTmp = $this->parseMirCond(substr($this->CodeStrings[$r['string']], 4));
                                    break;
                                default:
                                    switch (substr($this->CodeStrings[$r['string']], 0, 3)) {
                                        case 'str':
                                            $rTmp = $this->parseMirStr(substr($this->CodeStrings[$r['string']], 3));
                                            break;
                                        default:
                                            $rTmp = substr($this->CodeStrings[$r['string']], 1);
                                    }
                            }

                            break;
                        case 'string':
                            $rTmp = $r['string'];
                            break;
                        case 'boolean':
                            $rTmp = $r['boolean'] === 'true';
                            break;
                        default:
                            throw  new  errorException('Ошибка кода операции [[' . print_r($r, 1) . ']]');
                    }
                }

                if ($operator
                    ||
                    /*Фикс парсинга вычитания*/
                    (!is_null($res) && is_numeric($rTmp) && $rTmp < 0 && ($operator = "+"))) {
                    $res = $this->operatorExec($operator, $res, $rTmp);
                    $operator = null;
                } else {
                    if (!is_null($res)) {
                        throw new errorException('Ошибка кода - отсутствие оператора в выражении [[' . $code . ']] ' . var_export(
                            $codes,
                            1
                        ));
                    }

                    $res = $rTmp;
                }
            }

            if ($comparison) {
                $r = static::compare($comparison, $result, $result2);
                $result = $r;
            }

            $this->inVarsRevert($pastVals);

            $this->Table->calcLog($Log, 'result', $result);
        } catch (\Exception $e) {
            $this->Table->calcLog($Log, 'error', $e->getMessage());
            throw $e;
        }

        return $result;
    }

    protected function funcGetUsingFields($params)
    {
        $params = $this->getParamsArray($params);
        $tableRow = $this->__checkTableIdOrName($params['table'], 'table', 'getUsingFields');
        if (empty($params['field']) || !is_string($params['field'])) {
            throw new errorException('Параметр field - обязателен и должен быть строкой');
        }

        $query = <<<SQL
select table_name->>'v' as table_name, name->>'v' as name, version->>'v' as version from tables_fields where data->'v'->'code'->'==usedFields=='-> :table ->> :field = '1'
           OR data->'v'->'codeSelect'->'==usedFields=='-> :table ->> :field = '1'
            OR data->'v'->'codeAction'->'==usedFields=='-> :table ->> :field = '1';
SQL;


        return TablesFields::init($this->Table->getMir()->getConfig(), true)->executePreparedSimple(
            false,
            $query,
            ['table' => $tableRow['name'], 'field' => $params['field']]
        )->fetchAll();
    }

    protected function funcStrSplit($params)
    {
        $params = $this->getParamsArray($params);
        if (!key_exists('str', $params)) {
            throw new errorException('Параметр str является обязательным');
        }
        if (is_array($params['str'])) {
            throw new errorException('Параметр str не может быть массивом');
        }

        if (!key_exists('separator', $params)) {
            $list = [$params['str']];
        } elseif ($params['separator'] === '' || is_null($params['separator'])) {
            $list = str_split($params['str']);
        } elseif (is_array($params['separator'])) {
            throw new errorException('Параметр separator не может быть массивом');
        } else {
            $list = explode($params['separator'], $params['str']);
        }
        if (key_exists('limit', $params)) {
            if (!ctype_digit(strval($params['limit']))) {
                throw new errorException('Параметр limit должен быть числом');
            }
            if ($params['limit'] < count($list)) {
                $list = array_slice($list, 0, $params['limit']);
            }
        }

        return $list;
    }

    protected function funcLogRowList($params)
    {
        $params = $this->getParamsArray($params);
        $where = [];

        if (!ctype_digit((string)$params['table'])) {
            $where['tableid'] = $this->__checkTableIdOrName($params['table'] ?? null, 'table', 'logRowList')['id'];
        } else {
            $where['tableid'] = $params['table'];
        }
        if (!empty($params['cycle'])) {
            $where['cycleid'] = (int)$params['cycle'];
        }
        if (!empty($params['id'])) {
            $where['rowid'] = (int)$params['id'];
        }
        $where['field'] = (string)($params['field'] ?? '');

        $fields = ['comment' => 'modify_text', 'dt' => 'dt', 'user' => 'userid', 'action' => 'action', 'value' => 'v'];
        if (empty($params['params']) || !is_array($params['params'])) {
            $params['params'] = array_keys($fields);
        }

        $fieldsStr = '';
        foreach ($params['params'] as $param) {
            if (key_exists($param, $fields)) {
                if ($fieldsStr) {
                    $fieldsStr .= ',';
                }
                $fieldsStr .= $fields[$param] . ' as ' . $param;
            }
        }
        if ($fieldsStr) {
            $data = $this->Table->getMir()->getModel('_log', true)->executePrepared(
                true,
                $where,
                $fieldsStr,
                'dt desc',
                key_exists('limit', $params) ? '0,' . ((int)$params['limit']) : null
            )->fetchAll(\PDO::FETCH_ASSOC);

            if (in_array('dt', $params['params'])) {
                foreach ($data as &$_row) {
                    $_row['dt'] = substr($_row['dt'], 0, 19);
                }
                unset($_row);
            }
            return $data;
        } else {
            throw new errorException('Задайте корректный params');
        }
    }

    protected function funcTableLogSelect($params)
    {
        $params = $this->getParamsArray($params);
        $this->__checkListParam($params['users'], 'users', 'TableLogSelect');
        $date_from = $this->__checkGetDate($params['from'], 'from', 'TableLogSelect');

        $date_to = $this->__checkGetDate($params['to'], 'to', 'TableLogSelect');
        $date_to->modify('+1 day');

        $date_to = $date_to->format('Y-m-d');
        $date_from = $date_from->format('Y-m-d');
        $data = [];
        if ($params['users']) {
            $slqData = $this->Table->getMir()->getModel('_log', true)->executePrepared(
                true,
                ['userid' => $params['users'], '>=dt' => $date_from, '<dt' => $date_to],
                'tableid, cycleid,rowid,field,modify_text,v,action,userid,dt',
                'dt'
            );

            $action = [];
            $tmp_data = [];
            foreach ($slqData as $row) {
                $row['dt'] = substr($row['dt'], 0, 19);

                $tmp_action = [$row['userid'], $row['tableid'], $row['cycleid'], $row['rowid'], $row['action'], $row['dt']];
                if (array_slice($action, 0, 5) == array_slice($tmp_action, 0, 5)) {
                    $Date = date_create($action[5]);
                    $Date->modify('+1 second');
                    if ($Date->format('Y-m-d H:i:s') >= $row['dt']) {
                        $tmp_data[] = $row;
                        $action = $tmp_action;
                        continue;
                    }
                }

                if (!empty($tmp_data)) {
                    $fields = [];
                    foreach ($tmp_data as $t) {
                        if ($t['field']) {
                            $fields[$t['field']] = [$t['v'], $t['modify_text']];
                        } elseif ($t['modify_text'] && (string)$row['action'] === "4") {
                            $fields["Удаление"] = $t['modify_text'];
                        }
                    }

                    $data[] = ['userid' => $row['userid'], 'tableid' => $tmp_data[0]['tableid'], 'cycleid' => $tmp_data[0]['cycleid'], 'rowid' => $tmp_data[0]['rowid'], 'action' => $tmp_data[0]['action'], 'dt' => $tmp_data[0]['dt'], 'fields' => $fields];
                }
                $tmp_data = [$row];


                $action = $tmp_action;
            }

            if (!empty($tmp_data)) {
                $fields = [];
                foreach ($tmp_data as $t) {
                    if ($t['field']) {
                        $fields[$t['field']] = [$t['v'], $t['modify_text']];
                    }
                }
                $data[] = ['userid' => $row['userid'], 'tableid' => $tmp_data[0]['tableid'], 'cycleid' => $tmp_data[0]['cycleid'], 'rowid' => $tmp_data[0]['rowid'], 'action' => $tmp_data[0]['action'], 'dt' => $tmp_data[0]['dt'], 'fields' => $fields];
            }

            if (!empty($params['order'])) {
                usort(
                    $data,
                    function ($a, $b) use ($params) {
                        foreach ($params['order'] as $o) {
                            if (!key_exists(
                                $o['field'],
                                $a
                            )) {
                                throw new errorException('Поля ' . $o['field'] . ' в данных не обраружено');
                            }
                            if ($a[$o['field']] != $b[$o['field']]) {
                                $r = $a[$o['field']] < $b[$o['field']] ? -1 : 1;
                                if ($o['ad'] === 'desc') {
                                    return -$r;
                                }
                                return $r;
                            }
                        }
                        return 0;
                    }
                );
            }
        }
        return $data;
    }

    protected function funcListMath($params)
    {
        $params = $this->getParamsArray($params, ['list']);

        $list = $params['list'][0] ?? false;
        $this->__checkListParam($list, 'list', 'listMath');

        switch ($params['operator'] ?? '') {
            case '+':
                $func = function ($l, $num) {
                    return round($l + $num, 10);
                };

                break;
            case '-':
                $func = function ($l, $num) {
                    return round($l - $num, 10);
                };
                break;
            case '*':
                $func = function ($l, $num) {
                    return round($l * $num, 10);
                };
                break;
            case '^':
                $func = function ($l, $num) {
                    return pow($l, $num);
                };
                break;
            case '/':
                $func = function ($l, $num) {
                    if ((float)($num) === 0) {
                        throw new errorException('Деление на ноль');
                    }
                    return round($l / $num, 10);
                };
                break;
            default:
                throw new errorException('Параметр operator должен быть равен +,-,/,*');
        }

        for ($i = 1; $i < count($params['list']); $i++) {
            $list2 = $params['list'][$i] ?? false;
            $this->__checkListParam($list2, 'list2', 'listMath');
            foreach ($list as $k => &$l) {
                if (empty($l)) {
                    $l = 0;
                }

                if (!is_numeric((string)$l)) {
                    throw new errorException('Нечисловой параметр в листе');
                }
                if (!key_exists($k, $list2)) {
                    throw new errorException("Не существует ключа $k в листе " . ($i + 1));
                }
                if (empty($list2[$k])) {
                    $list2[$k] = 0;
                }
                if (!is_numeric((string)$list2[$k])) {
                    throw new errorException('Нечисловой параметр в листе ' . ($i + 1));
                }

                $l = $func($l, $list2[$k]);
            }
            unset($l);
        }


        if (key_exists('num', $params)) {
            $num = $params['num'];
            $this->__checkNumericParam($num, 'num', 'listMath');
            foreach ($list as &$l) {
                if (empty($l)) {
                    $l = 0;
                }
                if (!is_numeric((string)$l)) {
                    throw new errorException('Нечисловой параметр в листе');
                }
                $l = $func($l, $num);
            }
            unset($l);
        }
        return $list;
    }

    protected function funcFileGetContent($params)
    {
        $params = $this->getParamsArray($params);
        if (empty($params['file'])) {
            throw new errorException('Параметр file обязателен и не должен быть пустым');
        }

        return File::getContent($params['file'], $this->Table->getMir()->getConfig());
    }

    protected function funcStrRegMatches($params)
    {
        $params = $this->getParamsArray($params);

        if ($r = preg_match(
            '/' . str_replace('/', '\/', $params['template']) . '/'
            . ($params['flags'] ?? 'u'),
            $params['str'],
            $matches
        )) {
            if ($params['matches'] ?? null) {
                $this->vars[$params['matches']] = $matches;
            }
        }
        if ($r === false) {
            throw new errorException('Ошибка регулярного выражения: [[' . $params['template'] . ']]');
        }
        return !!$r;
    }

    protected function funcStrRegAllMatches($params)
    {
        $params = $this->getParamsArray($params);

        if ($r = preg_match_all(
            '/' . str_replace('/', '\/', $params['template']) . '/'
            . ($params['flags'] ?? 'u'),
            $params['str'],
            $matches
        )) {
            $this->vars[$params['matches']] = $matches;
        }
        if ($r === false) {
            throw new errorException('Ошибка регулярного выражения: [[' . $params['template'] . ']]');
        }
        return !!$r;
    }

    protected function funcWhile($params)
    {
        if ($vars = $this->getParamsArray(
            $params,
            ['action', 'preaction', 'postaction'],
            ['action', 'preaction', 'postaction', 'limit']
        )) {
            $iteratorName = $vars['iterator'] ?? '';

            //Типа транзаккция
            try {
                $return = null;

                if (!empty($vars['preaction'])) {
                    foreach ($vars['preaction'] as $i => $action) {
                        $return = $this->execSubCode($action, 'preaction' . (++$i));
                    }
                }

                if (!empty($vars['action'])) {
                    $limit = (int)array_key_exists('limit', $vars) ? $this->execSubCode($vars['limit'], 'limit') : 1;
                    $whileIterator = 0;
                    $isPostaction = false;

                    while ($limit-- > 0) {
                        if ($iteratorName) {
                            $this->whileIterators[$iteratorName] = $whileIterator;
                        }

                        if (!isset($vars['condition'])) {
                            $conditionTest = true;
                        } else {
                            $conditionTest = true;
                            foreach ($vars['condition'] as $i => $c) {
                                $condition = $this->execSubCode($c, 'condition' . (1 + $i));
                                if (!is_bool($condition)) {
                                    throw new errorException('Параметр [[condition' . (1 + $i) . ']] вернул не true/false');
                                }
                                if (!$condition) {
                                    $conditionTest = false;
                                    break;
                                }
                            }
                        }

                        if ($conditionTest) {
                            foreach ($vars['action'] as $i => $action) {
                                $return = $this->execSubCode($action, 'action' . (++$i));
                            }
                            $isPostaction = true;
                        } else {
                            break;
                        }


                        $whileIterator++;
                    }

                    if ($isPostaction && !empty($vars['postaction'])) {
                        foreach ($vars['postaction'] as $i => $action) {
                            $return = $this->execSubCode($action, 'postaction' . (++$i));
                        }
                    }
                }

                return $return;
            } catch (errorException $e) {
                throw $e;
            }
        } else {
            throw new errorException('Ошибка параметров функции While');
        }
    }

    protected function getExecParamVal($paramVal)
    {
        try {
            $codes = $this->getCodes($paramVal);
        } catch (errorException $e) {
            throw new errorException('Неправильно оформлено [[' . $paramVal . ']]');
        }

        if (count($codes) < 2) {
            throw new errorException('Параметр  должен содержать 2 элемента ');
        }

        if (is_array($codes[0])) {
            $varName = $this->__getValue($codes[0]);
        } else {
            $varName = $codes[0];
        }

        if (is_array($codes[1])) {
            $value = $this->__getValue($codes[1]);
        } else {
            $value = $codes[1];
        }
        return [$varName => $value];
    }

    protected function getParam($param, $paramArray)
    {
        $r = null;
        $isHashtag = false;
        if (strlen($param) === 0) {
            throw new errorException('Пусто параметр в поле [[' . $this->varName . ']]');
        }


        switch ($param[0]) {
            case '@':
                if ($param[1] === '$') {
                    $paramName = substr($param, 2);
                    $r = $this->Table->getMir()->getConfig()->globVar($paramName);
                } else {
                    $r = $this->Table->getSelectByParams(
                        ['table' => $paramArray['table'], 'field' => $paramArray['field']],
                        'field',
                        $this->row['id'] ?? null,
                        get_class($this) === Calculate::class
                    );
                }
                $isHashtag = true;
                break;
            case '$':
                if ($param[1] === '@') {
                    $paramName = substr($param, 2);
                    $r = $this->Table->getMir()->getConfig()->procVar($paramName);
                } elseif ($param[1] === '#') {
                    $nameVar = substr($param, 2);
                    switch ($nameVar) {
                        case 'nh':
                            $r = $this->Table->getMir()->getConfig()->getFullHostName();
                            break;
                        case 'nti':
                            $r = $this->funcNowTableId();
                            break;
                        case 'ntn':
                            $r = $this->funcNowTableName();
                            break;
                        case 'nth':
                            $r = $this->funcNowTableHash();
                            break;
                        case 'nf':
                            $r = $this->funcNowField();
                            break;
                        case 'nfv':
                            $r = $this->funcNowFieldValue();
                            break;
                        case 'onfv':
                            $r = $this->getParam('#old.' . $this->varName, ['param' => '#old.' . $this->varName]);
                            break;
                        case 'nci':
                            $r = $this->funcNowCycleId();
                            break;
                        case 'nd':
                            $r = date('Y-m-d');
                            break;
                        case 'ndt':
                            $r = date('Y-m-d H:i');
                            break;
                        case 'ndts':
                            $r = date('Y-m-d H:i:s');
                            break;
                        case 'lc':
                            $r = [];
                            break;
                        case 'nr':
                            $r = $this->funcNowRoles();
                            break;
                        case 'nu':
                            $r = $this->funcNowUser();
                            break;
                        case 'nl':
                            $r = "\n";
                            break;
                        case 'tb':
                            $r = "\t";
                            break;
                        case 'duplicatedId':
                            $r = $this->vars[$nameVar] ?? 0;
                            break;
                        case 'ih':
                            $r = $this->Table->getInsertRowHash();
                            break;
                        default:
                            if (array_key_exists($nameVar, $this->whileIterators)) {
                                $r = $this->whileIterators[$nameVar];
                            } else {
                                if (!array_key_exists($nameVar, $this->vars)) {
                                    throw new errorException('Переменная  [[' . $nameVar . ']] не определена');
                                }
                                $r = $this->vars[$nameVar];
                            }
                    }

                    $isHashtag = true;
                } else {
                    if ($param[1] === '$') {
                        $codeName = $this->getParam(
                            $param = substr($param, 1),
                            ['type' => 'param', 'param' => $param]
                        );
                    } else {
                        $codeName = substr($param, 1);
                    }

                    $inVars = [];
                    if ($varsStart = strpos($codeName, '{')) {
                        $codeNum = substr($codeName, $varsStart + 1, -1);
                        if ($codeNum !== "") {
                            $vars = $this->CodeLineParams[$codeNum];
                            $vars = $this->getParamsArray($vars, ['var'], ['var']);
                            foreach ($vars['var'] ?? [] as $var) {
                                $inVars = array_merge($inVars, $this->getExecParamVal($var));
                            }
                        }
                        $codeName = substr($codeName, 0, $varsStart);
                    }

                    if (!array_key_exists($codeName, $this->code)) {
                        throw new errorException('Код [[' . $codeName . ']] не найден');
                    }

                    /** ~codeName **/
                    if (in_array($codeName, $this->fixedCodeNames)) {
                        $cacheCodeName = $codeName . json_encode($inVars, JSON_UNESCAPED_UNICODE);
                        if (!array_key_exists($cacheCodeName, $this->fixedCodeVars)) {
                            $this->fixedCodeVars[$cacheCodeName] = $this->execSubCode(
                                $this->code[$codeName],
                                $param,
                                false,
                                $inVars
                            );
                        } else {
                            $Log = $this->Table->calcLog(['name' => $codeName, 'type' => "fixed"]);
                            $this->Table->calcLog($Log, 'result', $this->fixedCodeVars[$cacheCodeName]);
                        }
                        $r = $this->fixedCodeVars[$cacheCodeName];
                    } else {
                        try {
                            $r = $this->execSubCode($this->code[$codeName], $param, false, $inVars);
                        } catch (errorException $e) {
                            $e->addPath('Линия кода [[' . $codeName . ']]');
                            throw $e;
                        }
                    }
                }
                break;
            case '#':
                $nameVar = substr($param, 1);

                if ($nameVar[0] === '$') {
                    $nameVar = $this->getParam($nameVar, ['type' => 'param', 'param' => $nameVar]);
                }

                if (array_key_exists($nameVar, $this->whileIterators)) {
                    $r = $this->whileIterators[$nameVar];
                } else {
                    if (preg_match('/^old\./i', $nameVar)) {
                        $nameVar = substr($nameVar, 4);

                        if (array_key_exists($nameVar, $this->oldRow ?? [])) {
                            $rowVar = $this->oldRow[$nameVar];
                        } elseif (array_key_exists($nameVar, $this->oldTbl['params'] ?? [])) {
                            $rowVar = $this->oldTbl['params'][$nameVar];
                        } else {
                            $rowVar = "";
                        }
                    } elseif (($substr = substr($nameVar, 0, 2)) === 's.' || $substr === 'l.') {
                        $paramArray['param'] = substr($nameVar, 2);

                        if ($fName = $this->getParam($paramArray['param'], $paramArray)) {
                            if ($selectField = ($this->Table->getFields()[$fName] ?? null)) {
                                $paramArray['param'] = '#' . $fName;

                                $Field = Field::init($selectField, $this->Table);
                                switch ($substr) {
                                    case 's.':
                                        $r = $Field->getSelectValue(
                                            $this->getParam($paramArray['param'], $paramArray),
                                            $this->row,
                                            $this->tbl
                                        );
                                        break;
                                    case 'l.':
                                        $r = $Field->getLevelValue(
                                            $this->getParam($paramArray['param'], $paramArray),
                                            $this->row,
                                            $this->tbl
                                        );
                                        break;
                                }
                            }
                        }
                    } elseif (preg_match('/^prv\./i', $nameVar)) {
                        $nameVar = substr($nameVar, 4);

                        if (!array_key_exists('PrevRow', $this->row)) {
                            throw new errorException('Предыдущая строка не найдена. Проверьте подключение поля Порядок и "Пересчитывать при изменении порядка" в настройках таблицы');
                        } else {
                            $rowVar = $this->row['PrevRow'][$nameVar] ?? "";
                        }
                    } else {
                        switch (substr($nameVar, 0, 2)) {
                            case 'h.':
                                $nameVar = substr($nameVar, 2);
                                $typeVal = 'h';
                                break;
                            case 'c.':
                                $nameVar = substr($nameVar, 2);
                                $typeVal = 'c';
                                break;
                        }

                        if ($nameVar === $this->varName && get_class($this) === Calculate::class) {
                            throw new errorException('Нельзя из кода Код обращаться к текущему значению поля');
                        } elseif (key_exists($nameVar, $this->row)) {
                            $rowVar = $this->row[$nameVar];
                        } elseif (key_exists($nameVar, $this->tbl['params'] ?? [])) {
                            $rowVar = $this->tbl['params'][$nameVar];
                        } elseif (key_exists(
                            $nameVar,
                            $this->oldRow ?? []
                        ) && !key_exists(
                            $nameVar,
                            $this->row ?? []
                        )) {
                            if (in_array($nameVar, Model::serviceFields)) {
                                $rowVar = null;
                            } else {
                                $rowVar = ['v' => null];
                            }
                        } elseif (key_exists($nameVar, $this->Table->getSortedFields()['filter'])) {
                            $rowVar = ['v' => null];
                        } elseif ($nameVar === 'id' && key_exists(
                            $this->varName,
                            $this->Table->getFields()
                        ) && $this->Table->getFields()[$this->varName]['category'] === 'column') {
                            $rowVar = null;
                        } else {
                            throw new errorException('Параметр [[' . $nameVar . ']] не найден');
                        }
                    }

                    if (isset($rowVar)) {
                        if (in_array($nameVar, Model::serviceFields)) {
                            $r = $rowVar;
                        } else {
                            if (is_string($rowVar)) {
                                $rowVar = json_decode($rowVar, true);
                            }

                            switch ($typeVal ?? null) {
                                case 'h':
                                    $r = $rowVar['h'] ?? false;
                                    break;
                                case 'c':
                                    $r = key_exists('c', $rowVar) ? $rowVar['c'] : ($rowVar['v'] ?? null);
                                    break;
                                default:
                                    $r = $rowVar['v'] ?? null;
                            }
                        }
                    }
                }
                $isHashtag = true;
                break;
            case '\'':
                $r = substr($param, 1, -1);
                break;
            case '"':
                $r = substr($this->CodeStrings[substr($param, 1, -1)], 1);
                break;
            default:
                if (in_array($param, ['true', 'false'])) {
                    $r = $param === 'true';
                } elseif (is_numeric($param)) {
                    $r = ctype_digit($param) ? (int)$param : (float)$param;
                } else {
                    $r = $param;
                }
        }


        $paramName = $param;
        if (!empty($paramArray['items'])) {
            $itemsNames = '';

            if (preg_match_all('/\[(.*?)(?:\]\]|\])/', $paramArray['items'], $items)) {
                foreach ($items[0] as $_item) {
                    $_item = substr($_item, 1, -1);
                    $isSection = $_item[0] === '[' && substr($_item, -1, 1) === ']';
                    if ($isSection) {
                        $_item = substr($_item, 1, -1);
                    }
                    $item = $this->getParam($_item, ['type' => 'param', 'param' => $_item]);
                    $itemsNames .= "[$item]";

                    if (is_numeric($item)) {
                        $item = (string)$item;
                    }

                    if ($isSection) {
                        if (is_array($r)) {
                            $r = array_map(
                                function ($_ri) use ($item) {
                                    if (!is_array($_ri) || !key_exists(
                                        $item,
                                        $_ri
                                    )) {
                                        throw new errorException('Ключ [[' . $item . ']] не обнаружен в одном из элементов массива');
                                    }
                                    return $_ri[$item];
                                },
                                $r
                            );
                        } else {
                            $itemsNames .= '...';
                            $r = null;
                            break;
                        }
                    } elseif (is_array($r) && array_key_exists($item, $r)) {
                        $r = $r[$item];
                    } else {
                        $itemsNames .= '...';
                        $r = null;
                        break;
                    }
                }
            }
            $paramName = $param . $itemsNames;
            $isHashtag = true;
        }

        if ($isHashtag) {
            $this->Table->getCalculateLog()->addParam($paramName, $r);
        }
        return $r;
    }

    protected function funcNumFormat($params)
    {
        $params = $this->getParamsArray($params);

        $value = (string)($params['num'] ?? null);

        if (is_numeric($value)) {
            return number_format(
                $value,
                $params['dectimals'] ?? 0,
                $params['decsep'] ?? ',',
                $params['thousandssep'] ?? ''
            )
                . ($params['unittype'] ?? '');
        }
    }

    protected function funcNumRand($params)
    {
        $params = $this->getParamsArray($params);
        if (key_exists('min', $params)) {
            if (key_exists('max', $params)) {
                return rand($params['min'] ?? 0, $params['max'] ?? 0);
            }
            return rand($params['min'] ?? 0);
        }
        return rand();
    }

    protected function diffDates($date1, $date2, $unit)
    {
        switch ($unit) {
            case 'year':
                $diff = $date1->diff($date2);
                return $diff->y + $diff->m / 12 + $diff->d / 365;
            case 'month':
                $diff = $date1->diff($date2);
                return $diff->m + $diff->y * 12 + $diff->d / 30;
            case 'minute':
                return ($date2->getTimestamp() - $date1->getTimestamp()) / (60);
            case 'hour':
                return ($date2->getTimestamp() - $date1->getTimestamp()) / (60 * 60);
            default:
                return ($date2->getTimestamp() - $date1->getTimestamp()) / (24 * 60 * 60);
        }
    }

    protected function funcIf($params)
    {
        if ($vars = $this->getParamsArray($params)) {
            if (empty($vars['condition'])) {
                throw new errorException('Не задан condition');
            }
            $conditionTest = true;
            foreach ($vars['condition'] as $i => $c) {
                $condition = $this->execSubCode($c, 'condition' . (1 + $i));
                if (!is_bool($condition)) {
                    throw new errorException('Параметр [[condition' . (1 + $i) . ']] вернул не true/false');
                }
                if (!$condition) {
                    $conditionTest = false;
                    break;
                }
            }

            if ($conditionTest) {
                if (array_key_exists('then', $vars)) {
                    return $this->execSubCode($vars['then'], 'then');
                } else {
                    return null;
                }
            } elseif (array_key_exists('else', $vars)) {
                return $this->execSubCode($vars['else'], 'else');
            } else {
                return null;
            }
        } else {
            throw new errorException('Ошибка параметров функции If');
        }
    }

    protected function funcStrBaseEncode($params)
    {
        $params = $this->getParamsArray($params);
        if (empty($params['str'])) {
            throw new errorException('Параметр str пуст');
        }
        return base64_encode($params['str']);
    }

    protected function funcStrBaseDecode($params)
    {
        $params = $this->getParamsArray($params);
        if (empty($params['str'])) {
            throw new errorException('Параметр str пуст');
        }
        return base64_decode($params['str']);
    }

    protected function funcDiffDates($params)
    {
        $vars = $this->getParamsArray($params, ['date']);
        if (empty($vars['date']) || count($vars['date']) != 2) {
            throw new errorException('Должно быть два параметра [[date]] в функции [[diffDates]]');
        }

        $date1 = $this->__checkGetDate($vars['date'][0], 'date - 1', 'diffDates');
        $date2 = $this->__checkGetDate($vars['date'][1], 'date - 2', 'diffDates');

        return $this->diffDates($date1, $date2, $vars['unit'] ?? 'day');
    }

    protected function funcDateDiff($params)
    {
        return $this->funcDiffDates($params);
    }

    protected function funcsysTranslit($params)
    {
        $vars = $this->getParamsArray($params);
        $str = $vars['str'] ?? '';
        return Formats::translit($str);
    }

    protected function funcstrRandom($params)
    {
        $characters = "";
        $numbers = "0123456789";
        $letters = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $simbols = "!@#$%^&*()_+=-%,.;:";

        $params = $this->getParamsArray($params);
        $length = (int)$params['length'] ?? 0;
        if ($length < 1) {
            throw new errorException('length должно быть больше 0');
        }
        if (array_key_exists('numbers', $params)) {
            switch ($params['numbers']) {
                case "true":
                    $characters .= $numbers;
                    break;
                case "false":
                    break;
                default:
                    $characters += strval($params['numbers']);
            }
        } else {
            $characters .= $numbers;
        }

        if (array_key_exists('letters', $params)) {
            switch ($params['letters']) {
                case "true":
                    $characters .= $letters;
                    break;
                case "false":
                    break;
                default:
                    $characters += strval($params['letters']);
            }
        }
        if (array_key_exists('simbols', $params)) {
            switch ($params['simbols']) {
                case "true":
                    $characters .= $simbols;
                    break;
                case "false":
                    break;
                default:
                    $characters .= strval($params['simbols']);
            }
        }
        if (!$characters) {
            throw new errorException('Не выбраны символы для генерации');
        }

        $charactersLength = mb_strlen($characters, 'utf-8');
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= mb_substr($characters, mt_rand(0, $charactersLength - 1), 1, 'utf-8');
        }
        return $randomString;
    }

    protected function funcStrAdd($params)
    {
        if (($vars = $this->getParamsArray($params, ['str'])) && !empty($vars['str']) || count($vars['str']) > 1) {
            ksort($vars);

            $str = '';

            foreach ($vars['str'] as $v) {
                if (is_array($v)) {
                    throw new errorException('Фукция StrAdd не принимает в качестве параметра List');
                }
                $str .= $v;
            }

            return $str;
        } else {
            throw new errorException('Ошибка параметров функции StrAdd');
        }
    }

    protected function funcStrGz($params)
    {
        if (($params = $this->getParamsArray($params)) && array_key_exists('str', $params)) {
            return gzencode($params['str']);
        } else {
            throw new errorException('Ошибка параметров функции StrGz');
        }
    }

    protected function funcStrUnGz($params)
    {
        if (($params = $this->getParamsArray($params)) && array_key_exists('str', $params)) {
            return gzdecode($params['str']);
        } else {
            throw new errorException('Ошибка параметров функции StrUnGz');
        }
    }

    protected function funcNowDate($params)
    {
        $params = $this->getParamsArray($params);
        return $this->dateFormat(date_create(), ($params['format'] ?? 'Y-m-d H:i'), $params['lang'] ?? null);
    }

    protected function funcNowField()
    {
        if (empty($this->varName)) {
            throw new errorException('В этом типе кода не подключен NowField. Мы исправимся - напишите нам');
        }
        return $this->varName;
    }

    protected function funcNowFieldValue()
    {
        if (empty($this->varName)) {
            throw new errorException('В этом типе кода не подключен NowField. Мы исправимся - напишите нам');
        }

        return $this->getParam('#' . $this->varName, ['type' => 'param', 'param' => '#' . $this->varName]);
    }


    protected function funcNowTableName()
    {
        return $this->Table->getTableRow()['name'];
    }

    protected function funcNowTableId()
    {
        return $this->Table->getTableRow()['id'];
    }

    protected function funcNowTableUpdatedDt()
    {
        return json_decode($this->Table->getSavedUpdated(), true)['dt'];
    }


    protected function funcNowCycleId()
    {
        if ($this->Table->getTableRow()['type'] != 'calcs') {
            throw new errorException('[[NowCycleId]] работает только из расчетной таблицы в цикле.');
        }
        return $this->Table->getCycle()->getId();
    }

    protected function funcErrorExeption($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!empty($params['text'])) {
                throw new errorException((string)$params['text']);
            }
        }
    }

    protected function funcStrReplace($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!array_key_exists('str', $params)) {
                throw new errorException('Ошибка параметрa str StrReplace');
            }
            if (!array_key_exists('from', $params)) {
                throw new errorException('Ошибка параметрa from StrReplace');
            }
            if (!array_key_exists('to', $params)) {
                throw new errorException('Ошибка параметрa to StrReplace');
            }

            return str_replace($params['from'], $params['to'], $params['str']);
        } else {
            throw new errorException('Ошибка параметров функции StrReplace');
        }
    }

    protected function funcStrTransform($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!array_key_exists('str', $params)) {
                throw new errorException('Ошибка параметрa str StrTransform');
            }

            switch ($params['to'] ?? '') {
                case 'upper':
                    return mb_convert_case($params['str'], MB_CASE_UPPER, "UTF-8");
                case 'lower':
                    return mb_convert_case($params['str'], MB_CASE_LOWER, "UTF-8");
                case 'capitalize':
                    return mb_convert_case($params['str'], MB_CASE_TITLE, "UTF-8");
                default:
                    throw new errorException('Ошибка параметрa to StrTransform');
            }
        } else {
            throw new errorException('Ошибка параметров функции StrTransform');
        }
    }

    protected function funcStrRepeat($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!array_key_exists('str', $params)) {
                throw new errorException('Ошибка параметрa str StrRepeat');
            }
            if (!array_key_exists('num', $params)) {
                throw new errorException('Ошибка count num StrRepeat');
            }

            return str_repeat($params['str'], (int)$params['num']);
        } else {
            throw new errorException('Ошибка параметров функции StrRepeat');
        }
    }

    protected function funcListRepeat($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!array_key_exists('item', $params)) {
                throw new errorException('Ошибка параметрa item ListRepeat');
            }
            if (!array_key_exists('num', $params)) {
                throw new errorException('Ошибка count num ListRepeat');
            }

            return array_fill(0, (int)$params['num'], $params['item']);
        } else {
            throw new errorException('Ошибка параметров функции ListRepeat');
        }
    }

    protected function funcStrLength($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!array_key_exists(
                'str',
                $params
            ) || is_array($params["str"])) {
                throw new errorException('Ошибка параметрa str strLength');
            }

            return mb_strlen($params['str'], 'utf-8');
        } else {
            throw new errorException('Ошибка параметров функции strLength');
        }
    }

    protected function funcStrMd5($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (!array_key_exists(
                'str',
                $params
            ) || is_array($params["str"])) {
                throw new errorException('Ошибка параметрa str strMdF');
            }

            return md5($params['str']);
        } else {
            throw new errorException('Ошибка параметров функции strMdF');
        }
    }

    protected function funcExecSSH($params)
    {
        if (!$this->Table->getMir()->getConfig()->isExecSSHOn()) {
            throw new criticalErrorException('ExecSSH выключена. Подключите ее в Conf.php');
        }
        $params = $this->getParamsArray($params);
        if (empty($params['ssh'])) {
            throw new errorException('Параметр ssh обязателен');
        }
        $string = $params['ssh'];
        if ($params['vars'] ?? null) {
            $localeOld = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, "en_US.UTF-8");

            if (!is_array($params['vars'])) {
                throw new errorException('Параметр vars должен быть списком или ассоциативным массивом');
            }
            if (key_exists('0', $params['vars'])) {
                foreach ($params['vars'] as $v) {
                    $string .= ' ' . escapeshellarg($v) . '';
                }
            } else {
                foreach ($params['vars'] as $k => $v) {
                    $string .= ' ' . escapeshellcmd($k) . '=' . escapeshellarg($v) . '';
                }
            }
            setlocale(LC_CTYPE, $localeOld);
        }
        return shell_exec($string);
    }

    protected function funcDateAdd($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (empty($params['date'])) {
                return null;
            }
            $date = $this->__checkGetDate($params['date'], 'date', 'dateAdd');


            foreach (['days' => 'day', 'hours' => 'hour', 'minutes' => 'minute', 'years' => 'year', 'months' => 'month'] as $period => $datePeriodStr) {
                if (!empty($params[$period])) {
                    $this->__checkNumericParam($params[$period], $period, 'dateAdd');

                    $periodVal = intval($params[$period]);
                    if ($periodVal > 0) {
                        $periodVal = '+' . $periodVal;
                    }

                    $date->modify($periodVal . ' ' . $datePeriodStr);
                }
            }
            return $this->dateFormat($date, ($params['format'] ?? 'Y-m-d H:i'), $params['lang'] ?? null);
        } else {
            throw new errorException('Ошибка параметров функции DateAdd');
        }
    }

    protected function funcJsonExtract($params)
    {
        if ($params = $this->getParamsArray($params)) {
            return json_decode($params['text'] ?? null, true);
        }
    }

    protected function funcJsonCreate($params)
    {
        if ($params = $this->getParamsArray($params, ['field', 'flag'], ['field'])) {
            $data = $params['data'] ?? [];
            foreach ($params['field'] ?? [] as $f) {
                $f = $this->getExecParamVal($f);
                if (ctype_digit(strval(array_keys($f)[0]))) {
                    $data = $f + $data;
                } else {
                    $data = array_merge($data, $f);
                }
            }
            $flags = 0;
            if (key_exists('flag', $params)) {
                $escaped = false;
                foreach ($params['flag'] as $flag) {
                    switch ($flag) {
                        case 'ESCAPED_UNICODE':
                            $escaped = true;
                            break;
                        case 'PRETTY':
                            $flags = $flags | JSON_PRETTY_PRINT;
                            break;
                    }
                }
                if (!$escaped) {
                    $flags = $flags | JSON_UNESCAPED_UNICODE;
                }
            } else {
                $flags = JSON_UNESCAPED_UNICODE;
            }

            return json_encode($data, $flags);
        }
    }

    protected function __checkGetDate($dateFromParams, $paramName, $funcName)
    {
        if (empty($dateFromParams) || !($date = static::getDateObject($dateFromParams))) {
            throw new errorException('Ошибка формата параметра [[' . $paramName . ' = "' . $dateFromParams . '"]] функции [[' . $funcName . ']] [[' . $this->Table->getTableRow()['name'] . ']]');
        }
        return $date;
    }

    protected function funcDateFormat($params)
    {
        if ($params = $this->getParamsArray($params)) {
            if (($params['date'] ?? '') === '') {
                return '';
            }
            $date = $this->__checkGetDate(($params['date'] ?? ''), 'date', 'DateFormat');

            if (empty($params['format']) || !($formated = $this->dateFormat(
                $date,
                strval($params['format']),
                $params['lang'] ?? null
            ))) {
                throw new errorException('Ошибка  параметра format функции [[DateFormat]]');
            }

            return $formated;
        } else {
            throw new errorException('Ошибка параметров функции DateFormat');
        }
    }

    protected function dateFormat(\DateTime $date, $fStr, $lang = null): string
    {
        switch ($lang) {
            case 'ru':
                $result = '';
                $format = new Formats;
                foreach (preg_split(
                    '/([DlMF])/',
                    $fStr,
                    null,
                    PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
                ) as $split) {
                    $var = null;
                    switch ($split) {
                        case 'D':
                            $var = "weekDaysShort";
                        // no break
                        case 'l':
                            $var = $var ?? "weekDays";
                            $result .= $format->getConstant($var)[$date->format('N')];
                            break;
                        case 'F':
                            $var = "months";
                        // no break
                        case 'M':
                            $var = $var ?? "monthsShort";
                            $result .= $format->getConstant($var)[$date->format('n')];
                            break;
                        default:
                            $result .= $date->format($split);
                    }
                }
                return $result;
            default:
                return $date->format($fStr);
        }
    }

    protected function funcDateWeekDay($params)
    {
        if ($params = $this->getParamsArray($params)) {
            $date = $this->__checkGetDate(($params['date'] ?? ''), 'date', 'DateFormat');

            if (empty($params['format']) || !in_array($params['format'], ['number', 'short', 'full'])) {
                throw new errorException('Ошибка  параметра format функции [[DateFormat]]');
            }

            switch ($params['format']) {
                case 'number':
                    $formated = $date->format('N');
                    break;
                case 'short':
                    $formated = Formats::weekDaysShort[$date->format('N')];
                    break;
                case 'full':
                    $formated = Formats::weekDays[$date->format('N')];
                    break;
                default:
                    throw new errorException('Ошибка параметра format функции DateFormat');
            }

            return $formated;
        } else {
            throw new errorException('Ошибка параметров функции DateFormat');
        }
    }

    protected function funcNowUser()
    {
        return strval($this->Table->getMir()->getUser()->getId());
    }

    protected function funcUserInRoles($params)
    {
        if ($params = $this->getParamsArray($params, ['role'])) {
            $roles = $this->Table->getMir()->getUser()->getRoles();
            foreach ($params['role'] ?? [] as $role) {
                if (in_array($role, $roles)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function funcNowRoles()
    {
        return $this->Table->getMir()->getUser()->getRoles();
    }

    protected function funcListMax($params)
    {
        $params = $this->getParamsArray($params);

        $this->__checkListParam($params['list'], 'list', 'ListMax');

        $max = null;
        foreach ($params['list'] as $l) {
            $l = strval($l);
            if (is_null($max)) {
                $max = $l;
                continue;
            }
            if (is_numeric($l) && is_numeric($max)) {
                if (floatval($max) < floatval($l)) {
                    $max = $l;
                }
            } elseif ($l > $max) {
                $max = $l;
            }
        }
        if (is_null($max)) {
            if (array_key_exists('default', $params)) {
                return $params['default'];
            }

            throw new errorException('Нет значений для выборки максимального');
        }

        return $max;
    }

    protected function funcGetVar($params)
    {
        $params = $this->getParamsArray($params, [], ['default']);
        if (empty($params['name'])) {
            throw new errorException('Параметр  name должен быть заполнен');
        }
        if (!array_key_exists(
            $params['name'],
            $this->vars
        )) {
            if (array_key_exists('default', $params)) {
                $this->vars[$params['name']] = $this->execSubCode($params['default'], 'default');
            } else {
                throw new errorException('Параметр [[' . $params['name'] . ']] не был установлен в этом коде');
            }
        }
        return $this->vars[$params['name']];
    }

    protected function funcSetVar($params)
    {
        $params = $this->getParamsArray($params);
        if (empty($params['name'])) {
            throw new errorException('Параметр  name должен быть заполнен');
        }
        if (!array_key_exists('value', $params)) {
            throw new errorException('Параметр  value должен быть заполнен');
        }
        return $this->vars[$params['name']] = $params['value'];
    }

    protected function funcVar($params)
    {
        $params = $this->getParamsArray($params, [], ['default']);
        if (empty($params['name'])) {
            throw new errorException('Параметр  name должен быть заполнен');
        }

        if (array_key_exists('value', $params)) {
            $this->vars[$params['name']] = $params['value'];
        } elseif (array_key_exists($params['name'], $this->vars)) {
        } elseif (array_key_exists('default', $params)) {
            $this->vars[$params['name']] = $this->execSubCode($params['default'], 'default');
        } else {
            throw new errorException('Параметр [[' . $params['name'] . ']] не был установлен в этом коде');
        }
        return $this->vars[$params['name']];
    }


    protected function funcListMin($params)
    {
        $params = $this->getParamsArray($params);

        $this->__checkListParam($params['list'], 'list', 'ListMin');

        $min = null;
        foreach ($params['list'] as $l) {
            $l = strval($l);
            if (is_null($min)) {
                $min = $l;
                continue;
            }
            if (is_numeric($l) && is_numeric($min)) {
                if (floatval($min) > floatval($l)) {
                    $min = $l;
                }
            } elseif ($l < $min) {
                $min = $l;
            }
        }
        if (is_null($min)) {
            if (array_key_exists('default', $params)) {
                return $params['default'];
            }

            throw new errorException('Нет значений для выборки минимального');
        }

        return $min;
    }

    protected function funcListItem($params)
    {
        $params = $this->getParamsArray($params);

        $this->__checkListParam($params['list'], 'list', 'ListItem');
        if (is_null($params['item'])) {
            throw new errorException('Не передан параметр item');
        }


        return $params['list'][$params['item']] ?? null;
    }

    protected function funcGetTableSource($params)
    {
        $params = $this->getParamsArray($params);
        return $this->Table->getSelectByParams(
            $params,
            "table",
            $this->row['id'] ?? null,
            get_class($this) === Calculate::class
        );
    }

    protected function funcGetTableUpdated($params)
    {
        $params = $this->getParamsArray($params);
        if (empty($params['table'])) {
            throw new errorException('Не задан параметр таблица');
        }

        $sourceTableRow = $this->Table->getMir()->getTableRow($params['table']);

        if (!$sourceTableRow) {
            throw new errorException('Таблица [[' . $params['table'] . ']] не найдена');
        }

        if ($sourceTableRow['type'] === 'calcs') {
            $SourceCycle = $this->Table->getMir()->getCycle($params['cycle'], $sourceTableRow['tree_node_id']);
            $SourceTable = $SourceCycle->getTable($sourceTableRow);
        } else {
            $SourceTable = $this->Table->getMir()->getTable($sourceTableRow);
        }
        return json_decode($SourceTable->getSavedUpdated(), true);
    }

    protected function funcListSum($params)
    {
        $params = $this->getParamsArray($params);
        $this->__checkListParam($params['list'], 'list', 'ListSum');

        $sum = 0;
        foreach ($params['list'] as $l) {
            $sum += floatval($l);
        }

        return round($sum, 10);
    }

    protected function funcListCount($params)
    {
        $params = $this->getParamsArray($params);

        $this->__checkListParam($params['list'], 'list', 'ListCount');

        return count($params['list']);
    }

    protected function funcListCut($params)
    {
        $params = $this->getParamsArray($params);

        $this->__checkListParam($params['list'], 'list', 'ListCut');
        $list = $params['list'];
        $num = (int)($params['num'] ?? 1);

        if ($num !== 0) {
            if ($num > count($list)) {
                throw new errorException('List больше num');
            }
            switch ($params['cut'] ?? null) {
                case 'first':
                    array_splice($list, 0, $num);
                    break;
                case 'last':
                    array_splice($list, -$num, $num);
                    break;
                default:
                    throw new errorException('Отсутствует или некорректен параметр [cut]');
            }
        }
        return $list;
    }

    protected function funcListJoin($params)
    {
        $params = $this->getParamsArray($params);
        $this->__checkListParam($params['list'], 'list', 'ListJoin');

        return implode(($params['str'] ?? ''), $params['list']);
    }

    protected function funcListTrain($params)
    {
        $params = $this->getParamsArray($params);
        $this->__checkListParam($params['list'], 'list', 'ListJoin');

        $mainlist = [];
        foreach ($params['list'] as $list) {
            if (!is_array($list)) {
                throw new errorException('Один из элементов списка не список');
            }
            $mainlist = array_merge($mainlist, $list);
        }

        return $mainlist;
    }

    protected function funcListCreate($params)
    {
        $params = $this->getParamsArray($params, ['item']);
        return $params['item'] ?? [];
    }

    protected function funcListUniq($params)
    {
        $params = $this->getParamsArray($params);

        if ($params['list']) {
            $this->__checkListParam($params['list'], 'list', 'ListUniq');
            return array_values(
                array_unique(
                    $params['list'],
                    is_array($params['list'][0] ?? null) ? SORT_REGULAR : SORT_STRING
                )
            );
        } else {
            return [];
        }
    }

    protected function funcListMinus($params)
    {
        $params = $this->getParamsArray($params, ['list', 'item']);

        $MainList = null;

        if (!$params['list'][0]) {
            return [];
        }

        foreach ($params['list'] as $i => $list) {
            if ($list) {
                $this->__checkListParam($list, 'list' . (++$i), 'ListMinus');
                if (is_null($MainList)) {
                    $MainList = $list;
                } else {
                    $MainList = @array_diff($MainList, $list);
                }
            }
        }
        foreach ($params['item'] ?? [] as $i => $item) {
            $MainList = array_diff($MainList, [$item]);
        }

        return array_values($MainList);
    }

    protected function funcListSort($params)
    {
        $params = $this->getParamsArray($params, [], [], []);
        $this->__checkListParam($params['list'], 'list', 'listSort');

        $flags = 0;
        $params['type'] = $params['type'] ?? 'regular';
        switch ($params['type']) {
            case 'number':
                $flags = $flags | SORT_NUMERIC;
                break;
            case 'string':
                $flags = $flags | SORT_STRING;
                break;
            default:
                $flags = $flags | SORT_REGULAR;
        }

        switch ($params['key'] ?? 'value') {
            case 'key':
                if (!empty($params['direction']) && $params['direction'] === 'desc') {
                    $isAssoc = (array_keys($params['list']) !== range(
                        0,
                        count($params['list']) - 1
                    )) && count($params['list']) > 0;

                    if ($isAssoc) {
                        krsort($params['list'], $flags);
                    } else {
                        $params['list'] = array_reverse($params['list'], $flags);
                    }
                } else {
                    ksort($params['list'], $flags);
                }

                break;
            case 'item':
                if (is_null($params['item'] ?? null)) {
                    throw new errorException('Параметр item не определен');
                }

                if (!empty($params['direction']) && $params['direction'] === 'desc') {
                    $sort = SORT_DESC;
                } else {
                    $sort = SORT_ASC;
                }
                $column = array_column($params['list'], $params['item']);
                array_multisort($column, $flags, $sort, $params['list']);

                break;
            case 'value':
                $isAssoc = (array_keys($params['list']) !== range(
                    0,
                    count($params['list']) - 1
                )) && count($params['list']) > 0;
                if (!empty($params['direction']) && $params['direction'] === 'desc') {
                    if ($isAssoc) {
                        arsort($params['list'], $flags);
                    } else {
                        rsort($params['list'], $flags);
                    }
                } elseif ($isAssoc) {
                    asort($params['list'], $flags);
                } else {
                    sort($params['list'], $flags);
                }

                break;
            default:
                throw new errorException('Некорректный параметр key');
        }

        return $params['list'];
    }


    protected function funcListCross($params)
    {
        $params = $this->getParamsArray($params, ['list']);

        $MainList = null;

        foreach ($params['list'] as $i => $list) {
            $this->__checkListParam($list, 'list' . (++$i), 'ListCross');
            if (is_null($MainList)) {
                $MainList = $list;
            } else {
                $MainList = array_intersect($MainList, $list);
            }
        }

        return array_values($MainList);
    }

    protected function funclistReplace($params)
    {
        $params = $this->getParamsArray($params, ['action'], ['action'], []);
        $this->__checkListParam($params['list'], 'list', 'listReplace');
        $key = $params['key'] ?? null;
        $value = $params['value'] ?? null;

        if (!key_exists('action', $params)) {
            throw new errorException('Параметр action обязателен');
        }

        $actions = [];
        foreach ($params['action'] as $_a) {
            $actions[] = $this->getCodes($_a);
        }

        $list = $params['list'];
        foreach ($list as $k => $v) {
            $inVars = [];
            $pastVals = [];
            if ($key) {
                $inVars[$key] = $k;
            }
            if ($value) {
                $inVars[$value] = $v;
            }
            if ($inVars) {
                $pastVals = $this->inVarsApply($inVars);
            }

            foreach ($actions as $a => $action) {
                $Log = $this->Table->calcLog(['name' => 'iteration ' . $k . ' / action' . ($a + 1)]);

                try {
                    if (count($action) > 1) {
                        if (!is_array($v)) {
                            throw new errorException('Элемент с ключом [' . $k . '] не является row или list');
                        }
                        $_k = $this->__getValue($action[0]);
                        $_v = $this->__getValue($action[1]);
                        $list[$k][$_k] = $_v;
                        $this->Table->calcLog($Log, 'result', [$_k => $_v]);
                    } else {
                        $list[$k] = $this->__getValue($action[0]);
                        $this->Table->calcLog($Log, 'result', $list[$k]);
                    }
                } catch (\Exception $e) {
                    $this->Table->calcLog($Log, 'error', $e->getMessage());
                    throw $e;
                }
            }

            if ($pastVals) {
                $this->inVarsRevert($pastVals);
            }
        }

        return $list;
    }

    protected function funcListAdd($params)
    {
        $params = $this->getParamsArray($params, ['list', 'item']);


        $MainList = [];
        $this->__checkListParam($params['list'], 'list', 'listAdd');

        foreach ($params['list'] as $i => $list) {
            if ($list) {
                $this->__checkListParam($list, 'list' . (++$i), 'ListAdd');
                $MainList = array_merge($MainList, $list);
            }
        }
        foreach ($params['item'] ?? [] as $i => $item) {
            if (is_null($MainList)) {
                $MainList = [$item];
            } else {
                $MainList[] = $item;
            }
        }
        return array_values($MainList);
    }

    protected function funcRowAdd($params)
    {
        $params = $this->getParamsArray($params, ['row', 'field'], ['field']);

        $MainList = [];

        foreach ($params['row'] as $i => $row) {
            if ($row) {
                $this->__checkListParam($row, 'row' . (++$i), 'RowAdd');
                $MainList = array_replace($MainList, $row);
            }
        }
        foreach ($params['field'] ?? [] as $i => $field) {
            $field = $this->getExecParamVal($field);
            $k = array_keys($field)[0];
            $v = array_values($field)[0];
            if (is_null($MainList)) {
                $MainList = [$k => $v];
            } else {
                $MainList[$k] = $v;
            }
        }
        return $MainList;
    }

    protected function funcListNumberRange($params)
    {
        $params = $this->getParamsArray($params);
        $this->__checkNumericParam($params['min'], 'min');
        $this->__checkNumericParam($params['max'], 'max');
        $this->__checkNumericParam($params['step'], 'step');

        if ($params['step'] == 0) {
            throw new errorException('step не может равняться 0');
        } elseif ($params['step'] > 0) {
            $list = [$next = $params['min']];
            while (($next += $params['step']) < $params['max']) {
                $list[] = $next;
            }
        } else {
            $list = [$next = $params['max']];
            while (($next += $params['step']) > $params['min']) {
                $list[] = $next;
            }
        }


        return $list;
    }

    protected function funcRowListAdd($params)
    {
        $params = $this->getParamsArray($params, ['rowlist', 'field'], ['field']);

        $MainList = [];

        foreach ($params['rowlist'] as $i => $rowList) {
            if ($rowList) {
                $this->__checkListParam($rowList, 'rowlist' . (++$i), 'RowListAdd');
                $max = count($MainList) > count($rowList) ? count($MainList) : count($rowList);
                for ($i = 0; $i < $max; $i++) {
                    $MainList[$i] = array_replace($MainList[$i] ?? [], $rowList[$i] ?? []);
                }
            }
        }
        foreach ($params['field'] ?? [] as $i => $field) {
            $field = $this->getExecParamVal($field);
            $k = array_keys($field)[0];
            $v = array_values($field)[0];
            if (is_array($v) && array_key_exists(0, $v)) {
                $max = count($MainList) > count($v) ? count($MainList) : count($v);
                for ($i = 0; $i < $max; $i++) {
                    $MainList[$i] = array_replace($MainList[$i] ?? [], [$k => $v[$i] ?? null]);
                }
            } else {
                $max = count($MainList);
                for ($i = 0; $i < $max; $i++) {
                    $MainList[$i] = array_replace($MainList[$i] ?? [], [$k => $v]);
                }
            }
        }
        return $MainList;
    }

    protected function select($params, $mode, $withOutSection = false, $codeNameForLog = '')
    {
        $params = $this->getParamsArray($params, ['where', 'order', 'sfield']);

        if ($withOutSection) {
            unset($params['section']);
        }
        return $this->Table->getSelectByParams(
            $params,
            $mode,
            $this->row['id'] ?? null,
            get_class($this) == Calculate::class
        );
    }

    protected function funcSelect($params, $codeNameForLog)
    {
        return $this->select($params, 'field', false, $codeNameForLog);
    }

    protected function funcRowKeys($params)
    {
        $params = $this->getParamsArray($params, []);
        $this->__checkListParam($params['row'], 'row', 'RowKeys');
        return array_keys($params['row']);
    }

    protected function funcRowKeysRemove($params)
    {
        $params = $this->getParamsArray($params, ['key'], [], []);

        $this->__checkListParam($params['row'], 'row', 'RowKeysRemove');
        if (array_key_exists('keys', $params)) {
            $this->__checkListParam($params['keys'], 'keys', 'RowKeysRemove');
        }
        $keys = array_unique(array_merge(($params['key'] ?? []), ($params['keys'] ?? [])));

        if (!empty($keys) && !empty($params['row'])) {
            if ($params['recursive'] ?? false) {
                $remover = function ($row) use (&$remover, $keys) {
                    foreach ($keys as $key) {
                        unset($row[$key]);
                    }
                    foreach ($row as $k => $item) {
                        if (is_array($item)) {
                            $row[$k] = $remover($item);
                        }
                    }
                    return $row;
                };
            } else {
                $remover = function ($row) use (&$remover, $keys) {
                    foreach ($keys as $key) {
                        unset($row[$key]);
                    }
                    return $row;
                };
            }
            $row = $remover($params['row']);
        } else {
            $row = $params['row'];
        }

        return $row;
    }

    protected function funcRowKeysReplace($params)
    {
        $params = $this->getParamsArray($params, []);
        $this->__checkListParam($params['row'], 'row', 'Row');
        if (!array_key_exists('from', $params)) {
            throw new errorException('Ошибка параметрa from ');
        }
        if (!array_key_exists('to', $params)) {
            throw new errorException('Ошибка параметрa to ');
        }

        if (is_array($params['from']) && is_array($params['to'])) {
            if (count($params['from']) != count($params['to'])) {
                throw new errorException('Количество from не равно количеству to');
            }
        }

        if (is_array($params['to']) && !is_array($params['from'])) {
            throw new errorException('from не лист при to листе не имеет смысла');
        }

        $recursive = $params['recursive'] ?? false;


        if (is_array($params['from']) && is_array($params['to'])) {
            $funcKeyReplace = function ($k) use ($params) {
                $_seach = array_search(strval($k), $params['from']);
                if ($_seach !== false) {
                    return $params['to'][$_seach];
                }
                return $k;
            };
        } elseif (is_array($params['from'])) {
            $funcKeyReplace = function ($k) use ($params) {
                $_seach = array_search(strval($k), $params['from']);
                if ($_seach !== false) {
                    return $params["to"];
                }
                return $k;
            };
        } else {
            $funcKeyReplace = function ($k) use ($params) {
                if (strval($k) == $params['from']) {
                    return $params['to'];
                }
                return $k;
            };
        }


        $funcReplace = function ($row) use ($recursive, &$funcReplace, &$funcKeyReplace) {
            $rowOut = [];
            foreach ($row as $k => $v) {
                if ($recursive && is_array($v)) {
                    $vOut = $funcReplace($v);
                } else {
                    $vOut = $v;
                }
                $rowOut[$funcKeyReplace($k)] = $vOut;
            }
            return $rowOut;
        };

        return $funcReplace($params['row']);
    }

    protected function funcRowValues($params)
    {
        $params = $this->getParamsArray($params, []);
        $this->__checkListParam($params['row'], 'row', 'RowKeys');
        return array_values($params['row']);
    }

    protected function funcListFilter($params)
    {
        $params = $this->getParamsArray($params, []);
        $this->__checkListParam($params['list'], 'list', 'listFilter');
        if (empty($params['key'])) {
            throw new errorException('Параметр [[key]] обязателен');
        }
        $isGerExp = $params['regexp'] ?? false;

        if ($isGerExp) {
            $regExpFlags = $params['regexp'] !== true && $params['regexp'] !== 'true' ? $params['regexp'] : 'u';

            if (!in_array($params['key']['operator'], ['=', '!=', '!==', '==='])) {
                throw new errorException('regexp сравнивается только = и !=');
            } else {
                $operator = in_array($params['key']['operator'], ['=', '===']) ? true : false;
            }
            $pattern = '/' . str_replace('/', '\/', $params['key']['value']) . '/' . $regExpFlags;
            $matches = [];

            $getCompare = function ($v) use ($operator, $pattern, &$matches) {
                if (preg_match($pattern, $v, $_matches)) {
                    $matches[] = $_matches;
                    return $operator;
                }
                return !$operator;
            };
        } else {
            $operator = $params['key']['operator'];
            $value = $params['key']['value'];
            $getCompare = function ($v) use ($operator, $value) {
                return Calculate::compare($operator, $v, $value);
            };
        }

        switch ($params['key']['field']) {
            case 'value':
                $filter = function ($k, $v) use ($getCompare) {
                    return $getCompare($v);
                };
                break;
            case 'key':
                $filter = function ($k, $v) use ($getCompare) {
                    return $getCompare($k);
                };
                break;
            case 'item':
                if (!array_key_exists('item', $params)) {
                    throw new errorException('Параметр [[item]] не найден');
                }

                $skip = $params['skip'] ?? false;

                $filter = function ($k, $v) use ($params, $skip, $getCompare) {
                    if (!is_array($v)) {
                        if (!$skip) {
                            throw new errorException('Параметр не соответствует условиям фильтрации - значение не list');
                        }
                        return false;
                    } elseif (!array_key_exists(
                        $params['item'],
                        $v
                    )) {
                        if (!$skip) {
                            throw new errorException('Параметр не соответствует условиям фильтрации - item не найден');
                        }
                        return false;
                    }
                    return $getCompare($v[$params['item']]);
                };
                break;
            default:
                throw new errorException('Первый параметр от [[key]] должен быть равен "item", "key" или "value"');
        }

        $filtered = [];
        $nIsRow = false;
        if ((array_keys($params['list']) !== range(0, count($params['list']) - 1))) {
            $nIsRow = true;
        }
        foreach ($params['list'] as $k => $v) {
            if ($filter($k, $v)) {
                if ($nIsRow) {
                    $filtered[$k] = $v;
                } else {
                    $filtered[] = $v;
                }
            }
        }
        if ($isGerExp && ($params['matches'] ?? null)) {
            $this->vars[$params['matches']] = $matches;
        }

        return $filtered;
    }

    protected function funcListSection($params)
    {
        $params = $this->getParamsArray($params, []);
        $this->__checkListParam($params['list'], 'list', 'listFilter');
        if (!array_key_exists('item', $params)) {
            throw new errorException('Параметр [[item]] обязателен');
        }

        $filter = function ($v) use ($params) {
            if (!is_array($v)) {
                throw new errorException('Параметр не соответствует условиям фильтрации - значение не list');
            } elseif (!array_key_exists(
                $params['item'],
                $v
            )) {
                throw new errorException('Параметр не соответствует условиям фильтрации - ключ [[' . $params['item'] . ']] не найден');
            }
            return $v[$params['item']];
        };


        $filtered = [];
        foreach ($params['list'] as $k => $v) {
            $filtered[$k] = $filter($v);
        }

        return $filtered;
    }

    protected function funcListSearch($params)
    {
        $params = $this->getParamsArray($params, []);
        $this->__checkListParam($params['list'], 'list', 'listSearch');
        if (empty($params['key'])) {
            throw new errorException('Параметр [[key]] обязателен');
        }


        switch ($params['key']['field']) {
            case 'value':
                $filter = function ($k, $v) use ($params) {
                    return Calculate::compare($params['key']['operator'], $v, $params['key']['value']);
                };
                break;
            case 'item':
                if (!array_key_exists('item', $params)) {
                    throw new errorException('Параметр [[item]] не найден');
                }

                $filter = function ($k, $v) use ($params) {
                    if (!is_array($v)) {
                        throw new errorException('Параметр не соответствует условиям поиска - значение не list');
                    } elseif (!array_key_exists(
                        $params['item'],
                        $v
                    )) {
                        throw new errorException('Параметр не соответствует условиям поиска - item не найден');
                    }
                    return Calculate::compare($params['key']['operator'], $v[$params['item']], $params['key']['value']);
                };
                break;
            default:
                throw new errorException('Первый параметр от [[key]] должен быть равен "item" или "value"');
        }

        $filtered = [];
        foreach ($params['list'] as $k => $v) {
            if ($filter($k, $v)) {
                $filtered[] = $k;
            }
        }

        return $filtered;
    }

    protected function funcSelectRow($params, $codeNameForLog)
    {
        $params = $this->getParamsArray($params, ['where', 'order', 'field', 'sfield', 'tfield']);
        if (!empty($params['fields'])) {
            $params['field'] = array_merge($params['field'] ?? [], $params['fields']);
        }
        if (!empty($params['sfields'])) {
            $params['sfield'] = array_merge($params['sfield'] ?? [], $params['sfields']);
        }
        unset($params['section']);

        $row = $this->Table->getSelectByParams(
            $params,
            'row',
            $this->row['id'] ?? null,
            get_class($this) === Calculate::class
        );
        if (!empty($row['__sectionFunction'])) {
            $row = $row['__sectionFunction']();
        }
        return $row;
    }

    protected function funcSelectRowList($params, $codeNameForLog)
    {
        $params = $this->getParamsArray($params, ['where', 'order', 'field', 'sfield', 'tfield']);
        if (!empty($params['fields'])) {
            $params['field'] = array_merge($params['field'] ?? [], $params['fields']);
        }
        if (!empty($params['sfields'])) {
            $params['sfield'] = array_merge($params['sfield'] ?? [], $params['sfields']);
        }
        unset($params['section']);

        return $this->Table->getSelectByParams(
            $params,
            'rows',
            $this->row['id'] ?? null,
            get_class($this) === Calculate::class
        );
    }

    protected function funcRowCreateByLists($params)
    {
        $params = $this->getParamsArray($params, [], []);
        $this->__checkListParam($params['keys'], 'keys');
        $this->__checkListParam($params['values'], 'values');
        return array_combine($params['keys'], $params['values']);
    }

    protected function funcRowCreate($params)
    {
        $params = $this->getParamsArray($params, ['field'], ['field']);
        $row = [];
        foreach ($params['field'] as $f) {
            $f = $this->getExecParamVal($f);
            if (ctype_digit(strval(array_keys($f)[0]))) {
                $row = $f + $row;
            } else {
                $row = array_merge($row, $f);
            }
        }

        return $row;
    }

    protected function funcRowListCreate($params)
    {
        $params = $this->getParamsArray($params, ['field'], ['field']);

        $rows = [];
        $listCount = 0;
        foreach ($params['field'] as $f) {
            $f = $this->getExecParamVal($f);
            $rows = array_replace($rows, $f);
        }
        $rowList = [];
        foreach ($rows as $f => $list) {
            if (is_array($rows[$f]) && key_exists(0, $rows[$f])) {
                if (count($rows[$f]) > $listCount) {
                    $listCount = count($rows[$f]);
                }
            }
        }
        foreach ($rows as $f => &$list) {
            if (!is_array($rows[$f]) || !key_exists(0, $rows[$f])) {
                $list = array_fill(0, $listCount, $list);
            } elseif (count($list) < $listCount) {
                $diff = $listCount - count($list);
                for ($i = 0; $i < $diff; $i++) {
                    $list[] = null;
                }
            }
            $list = array_values($list);
        }
        unset($list);

        for ($i = 0; $i < $listCount; $i++) {
            $rowList[$i] = [];
            foreach ($rows as $f => $list) {
                $rowList[$i][$f] = $list[$i];
            }
        }
        return $rowList;
    }

    protected function funcRound($params)
    {
        $params = $this->getParamsArray($params);
        $val = $params['num'] ?? 0;

        $func = 'round';
        if (!empty($params['type'])) {
            switch ($params['type']) {
                case 'up':
                    $func = 'ceil';
                    break;
                case 'down':
                    $func = 'floor';
                    break;
            }
        }


        if (!empty($params['step'])) {
            $fig = (int)str_pad('1', $params['dectimal'] ?? 0 + 1, '0');
            $step = $params['step'] * $fig;

            $val = $func($val * $fig / $step) * $step / $fig;
            $val = round($val, 10);
            $val = bcadd($val, 0, $params['dectimal'] ?? 0);
        } else {
            $val = $func($val);
        }
        return $val;
    }

    protected function funcModul($params)
    {
        $params = $this->getParamsArray($params);
        $val = $params['num'] ?? 0;
        return abs($val);
    }

    protected function __checkTableIdOrName($tableId, $paramName, $funcName = null)
    {
        if (empty($tableId)) {
            throw new errorException('Не найден параметр [[' . $paramName . ']]');
        }

        $table = $this->Table->getMir()->getTableRow($tableId);
        if (!$table) {
            throw new errorException('Таблица [[' . $tableId . ']] не существует');
        }
        return $table;
    }

    protected function __checkNumericParam($isDigit, $paramName, $funcName = null)
    {
        if (is_null($isDigit)) {
            throw new errorException('Не найден параметр [[' . $paramName . ']]');
        }

        if (is_array($isDigit) || !is_numeric((string)$isDigit)) {
            throw new errorException('Параметр [[' . $paramName . ']] должен быть числом а не [['
                . json_encode($isDigit, JSON_UNESCAPED_UNICODE) . ']]');
        }
    }

    protected function __checkListParam(&$List, $paramName, $funcName = null)
    {
        if (is_null($List) || $List === "") {
            $List = [];
        }
        if (!is_array($List)) {
            throw new errorException('Параметр [[' . $paramName . ']] должен быть листом');
        }
    }

    protected function funcReCalculate($params)
    {
        if ($params = $this->getParamsArray($params, ['field'])) {
            $tableRow = $this->__checkTableIdOrName($params['table'], 'table', '$table->reCalculate(reCalculate');

            $inVars = [];
            if (key_exists('field', $params)) {
                $inVars['inAddRecalc'] = $params['field'];
            }
            if ($tableRow['type'] == 'calcs') {
                if (empty($params['cycle']) && $this->Table->getTableRow()['type'] === 'calcs' && $this->Table->getTableRow()['tree_node_id'] === $tableRow['tree_node_id']) {
                    $params['cycle'] = [$this->Table->getCycle()->getId()];
                }

                if (!is_array($params['cycle']) && empty($params['cycle'])) {
                    throw new errorException('Не указан [[cycle]] в функции [[reCalculate]]');
                }
                $Cycles = (array)$params['cycle'];
                foreach ($Cycles as $cycleId) {
                    $params['cycle'] = $cycleId;
                    $Cycle = $this->Table->getMir()->getCycle($params['cycle'], $tableRow['tree_node_id']);
                    /** @var calcsTable $table */
                    $table = $Cycle->getTable($tableRow);
                    $table->reCalculateFromOvers($inVars, $this->Table->getCalculateLog(), 0);
                }
            } elseif ($tableRow['type'] == 'tmp') {
                if ($this->Table->getTableRow()['type'] === 'tmp' && $this->Table->getTableRow()['name'] === $tableRow['name']) {
                    if (empty($params['hash'])) {
                        $table = $this->Table;
                    }
                }
                if (empty($table)) {
                    $table = $this->Table->getMir()->getTable($tableRow, $params['hash']);
                }
                $table->reCalculateFromOvers($inVars, $this->Table->getCalculateLog());
            } else {
                $table = $this->Table->getMir()->getTable($tableRow);

                if (is_subclass_of($table, RealTables::class) && !empty($params['where'])) {
                    $ids = $table->getByParams(['field' => 'id', 'where' => $params['where']], 'list');
                    $inVars['modify'] = array_fill_keys($ids, []);
                    $table->reCalculateFromOvers($inVars, $this->Table->getCalculateLog());
                } else {
                    $table->reCalculateFromOvers($inVars, $this->Table->getCalculateLog());
                }
            }
        }
    }

    protected function funcSelectTreeChildren($params)
    {
        $params = $this->getParamsArray($params);
        return $this->select($params, 'treeChildren');
    }

    protected function funcSelectList($params)
    {
        return $this->select($params, 'list');
    }

    protected function __getValue($paramArray)
    {
        switch ($paramArray['type']) {
            case 'param':
                return $this->getParam($paramArray['param'], $paramArray);
            case 'string':
                return $paramArray['string'];
            case 'stringParam':
                $spec = substr($this->CodeStrings[$paramArray['string']], 0, 4);

                switch ($spec) {
                    case 'math':
                        return $this->getMathFromString(substr($this->CodeStrings[$paramArray['string']], 4));
                    case 'json':
                        return $this->parseMirJson($str = substr($this->CodeStrings[$paramArray['string']], 4));
                    case 'cond':
                        return $this->parseMirCond($str = substr($this->CodeStrings[$paramArray['string']], 4));
                    default:
                        switch (substr($spec, 0, 3)) {
                            case 'str':
                                return $this->parseMirStr(substr($this->CodeStrings[$paramArray['string']], 3));
                        }
                        return substr($this->CodeStrings[$paramArray['string']], 1);
                }
            // no break
            case 'boolean':
                return $paramArray['boolean'] == 'false' ? false : true;
            default:
                throw new errorException('Неверное оформление кода [[' . $paramArray['type'] . ']] не там, где может быть');
        }
    }

    protected function getParamsArray($paramsString, $arrayParams = [], $notExecParams = [], $threePartParams = ['where', 'filter', 'key'])
    {
        if (is_array($paramsString)) {
            return $paramsString;
        }

        $notExecParams = array_merge($notExecParams, ['condition', 'then', 'else']);
        $arrayParams = array_merge($arrayParams, ['where', 'order', 'filter', 'condition', 'preview']);

        $params = [];
        /*Кеш матчей не ускоряет*/
        if (preg_match_all('/([a-z0-9_]{2,}):([^;]+)(;|$)/', $paramsString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $param = $match[1];
                $paramVal = null;
                if (in_array($param, $arrayParams)) {
                    if (!isset($params[$param])) {
                        $params[$param] = [];
                    }
                    $paramVal = &$params[$param][];
                } else {
                    $paramVal = &$params[$param];
                }

                if (isset($params[$param]) && !is_array($params[$param])) {
                    throw new errorException('Одинарный параметр [[' . $param . ']] использован несколько раз');
                }
                $paramVal = trim($match[2]);

                switch ($param) {
                    case 'order':
                        if (preg_match(
                            '/^(.*?)(?i:(asc|desc))?$/',
                            $paramVal,
                            $matches
                        )) {
                            $field = $this->execSubCode($matches[1], 'orderField');
                            $AscDesc = !empty($matches[2]) && strtolower($matches[2]) == 'desc' ? 'desc' : 'asc';

                            $paramVal = [
                                'field' => $field, //$this->getParam($field, []),
                                'ad' => $AscDesc
                            ];
                        } else {
                            throw new errorException('Неправильно оформлен параметр сортировки [[' . $paramVal . ']]');
                        }
                        break;
                    default:
                        if (in_array($param, $notExecParams)) {
                        } elseif (in_array($param, $threePartParams)) {
                            try {
                                $whereCodes = $this->getCodes($paramVal);
                            } catch (errorException $e) {
                                throw new errorException('Неправильно оформлено условие выборки [[' . $paramVal . ']] в поле [[' . $this->varName . ']] (' . $e->getMessage() . ')');
                            }

                            if (count($whereCodes) != 3) {
                                throw new errorException('Параметр ' . $param . ' должен содержать 3 элемента ');
                            }
                            if (empty($whereCodes['comparison'])) {
                                throw new errorException('Параметр ' . $param . ' должен содержать элемент сравнения ');
                            }
                            if ($param == 'filter' && $whereCodes['comparison'] != '=') {
                                throw new errorException('Параметр ' . $param . ' пока может принимать только =');
                            }

                            if (is_array($whereCodes[0])) {
                                $field = $this->__getValue($whereCodes[0]);
                            } else {
                                $field = $whereCodes[0];
                            }
                            if (is_array($whereCodes[1])) {
                                $value = $this->__getValue($whereCodes[1]);
                            } else {
                                $value = $whereCodes[1];
                            }

                            $paramVal = [
                                'field' => $this->getParam(
                                    $field,
                                    []
                                ), 'operator' => $whereCodes['comparison'], 'value' => $value
                            ];
                        } else {
                            $paramVal = $this->execSubCode($paramVal, $param, true);
                        }
                        break;
                }
                unset($paramVal);
            }
        }
        return $params;
    }

    protected function funcTextByTemplate($params)
    {
        $params = $this->getParamsArray($params);

        $getTemplate = function ($name) {
            return $this->Table->getMir()->getModel('print_templates')->getPrepared(
                ['name' => $name],
                'styles, html, name'
            );
        };

        if ($params['template'] ?? null) {
            if ($main = $getTemplate($params['template'])) {
                $mainTemplate = $main['html'];
                $style = $main['styles'];
            } else {
                throw new errorException('Шаблон не найден');
            }
        } else {
            if ($params['text'] ?? null) {
                $mainTemplate = $params['text'];
                $style = null;
            } else {
                throw new errorException('Шаблон не найден');
            }
        }


        $usedStyles = [];

        $funcReplaceTemplates = function ($html, $data) use (&$funcReplaceTemplates, $getTemplate, &$style, &$usedStyles) {
            return preg_replace_callback(
                '/{(([a-z_0-9]+)(\["[a-z_0-9]+"\])?(?:,([a-z]+(?::[^}]+)?))?)}/',
                function ($matches) use ($data, $getTemplate, &$funcReplaceTemplates, &$style, &$usedStyles) {
                    if (array_key_exists($matches[2], $data)) {
                        if (is_array($data[$matches[2]])) {
                            if (!empty($matches[3])) {
                                $matches[3] = substr($matches[3], 2, -2);
                                if (!array_key_exists(
                                    $matches[3],
                                    $data[$matches[2]]
                                )) {
                                    throw new errorException('Не найден ключ ' . $matches[3] . ' в параметре [' . $matches[2] . ']');
                                }
                                $value = $data[$matches[2]][$matches[3]];
                            } else {
                                if (!empty($data[$matches[2]]['template'])) {
                                    $template = $getTemplate($data[$matches[2]]['template']);
                                    if (!$template) {
                                        throw new errorException('Не найден template [' . $data[$matches[2]]['template'] . '] для параметра [' . $matches[2] . ']');
                                    }

                                    if (!in_array($template['name'], $usedStyles)) {
                                        $style .= $template['styles'];
                                        $usedStyles[] = $template['name'];
                                    }
                                } elseif (key_exists("text", $data[$matches[2]])) {
                                    $template = ['html' => $data[$matches[2]]["text"]];
                                } else {
                                    throw new errorException('Не указан template для параметра [' . $matches[2] . ']');
                                }

                                $html = '';


                                if (array_key_exists(0, $data[$matches[2]]['data'])) {
                                    foreach ($data[$matches[2]]['data'] ?? [] as $_data) {
                                        $html .= $funcReplaceTemplates($template['html'], $_data);
                                    }
                                } else {
                                    $html .= $funcReplaceTemplates(
                                        $template['html'],
                                        (array)$data[$matches[2]]['data']
                                    );
                                }

                                return $html;
                            }
                        } else {
                            $value = $data[$matches[2]];
                        }

                        if (!empty($matches[4])) {
                            if ($formatData = explode(':', $matches[4], 2)) {
                                switch ($formatData[0]) {
                                    case 'money':
                                        if (is_numeric($value)) {
                                            $value = Formats::num2str($value);
                                        }
                                        break;
                                    case 'number':
                                        if (count($formatData) == 2) {
                                            if (is_numeric($value)) {
                                                if ($numberVals = explode('|', $formatData[1])) {
                                                    $value = number_format(
                                                        $value,
                                                        $numberVals[0],
                                                        $numberVals[1] ?? '.',
                                                        $numberVals[2] ?? ''
                                                    )
                                                        . ($numberVals[3] ?? '');
                                                }
                                            }
                                        }
                                        break;
                                    case 'date':
                                        if (count($formatData) == 2) {
                                            if ($date = date_create($value)) {
                                                if (strpos($formatData[1], 'F') !== false) {
                                                    $formatData[1] = str_replace(
                                                        'F',
                                                        Formats::months[$date->format('n')],
                                                        $formatData[1]
                                                    );
                                                }
                                                if (strpos($formatData[1], 'f') !== false) {
                                                    $formatData[1] = str_replace(
                                                        'f',
                                                        Formats::monthRods[$date->format('n')],
                                                        $formatData[1]
                                                    );
                                                }
                                                $value = $date->format($formatData[1]);
                                            }
                                        }
                                        break;
                                    case 'checkbox':
                                        if (is_bool($value)) {
                                            $sings = [];
                                            if (count($formatData) == 2) {
                                                $sings = explode('|', $formatData[1] ?? '');
                                            }

                                            switch ($value) {
                                                case true:
                                                    $value = $sings[0] ?? '✓';
                                                    break;
                                                case false:
                                                    $value = $sings[1] ?? '-';
                                                    break;
                                            }
                                        }
                                        break;
                                }
                            }
                        }

                        return $value;
                    }
                },
                $html
            );
        };


        if ($style) {
            return '<style>' . $style . '</style><body>' . $funcReplaceTemplates(
                $mainTemplate,
                $params['data'] ?? []
            ) . '</body>';
        } else {
            return $funcReplaceTemplates($mainTemplate, $params['data'] ?? []);
        }
    }

    protected function funcNowTableHash()
    {
        if ($this->Table->getTableRow()['type'] != 'tmp') {
            throw new errorException('Hash можно запросить только у Временной таблицы');
        }
        return $this->Table->getTableRow()['sess_hash'];
    }

    protected function parseMirCond($string)
    {
        $string = preg_replace('/\s+/', '', $string);

        $actions = preg_split(
            '`(
                        \(|\)|
                        [&]{2}|
                        [|]{2}|
                        ==|
                        !=|
                        >=|
                        <=|
                        [><=]
                        )`x',
            $string,
            null,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $pack_Sc = function ($actions) use (&$pack_Sc, &$calcIt) {
            $sc = 0;
            $interval_start = null;
            $i = 0;
            while ($i < count($actions)) {
                if ($actions[$i] === "") {
                    array_splice($actions, $i, 1);
                    continue;
                }
                switch ((string)$actions[$i]) {
                    case '(':
                        if ($sc++ == 0) {
                            $interval_start = $i;
                        }
                        break;
                    case ')':
                        if ($sc < 1) {
                            throw new errorException('Непарная закрывающая скобка');
                        }
                        if (--$sc == 0) {
                            array_splice(
                                $actions,
                                $interval_start,
                                $i + 1 - $interval_start,
                                $calcIt($pack_Sc(array_slice(
                                    $actions,
                                    $interval_start + 1,
                                    $i - $interval_start - 1
                                )))
                            );
                            $i = $interval_start - 1;
                        }
                        break;
                }
                $i++;
            }
            return $actions;
        };

        $checkValue = function ($varIn, $onlyBool = true) {
            if ($varIn === 'false' || $varIn === false) {
                return false;
            }
            if ($varIn === 'true' || $varIn === true) {
                return true;
            }

            $var = $this->execSubCode($varIn, 'CondCode ' . $varIn);

            if ($onlyBool) {
                if ($var === 'false' || $var === false) {
                    return false;
                }
                if ($var === 'true' || $var === true) {
                    return true;
                }
                throw new errorException('Ошибка вычисления cond:' . $varIn . ' вернул не bool');
            }
            return $var;
        };

        $getValue = function ($var) use ($checkValue) {
            if ($var && !is_numeric($var)) {
                $var = $checkValue($var, false);
            }
            return $var;
        };

        $calcIt = function ($action) use ($checkValue, $getValue, $string) {
            $i = 0;
            while ($i < count($action)) {
                switch ((string)$action[$i]) {
                    case '<':
                    case '>':
                    case '=':
                    case '==':
                    case '!=':
                    case '<=':
                    case '>=':

                        $left = $getValue($action[$i - 1]);
                        $right = $getValue($action[$i + 1]);

                        $val = static::compare($action[$i], $left, $right);
                        array_splice($action, $i - 1, 3, $val);
                        $i--;
                }
                $i++;
            }


            $i = 0;

            while ($i < count($action)) {
                switch ((string)$action[$i]) {
                    case '&&':
                        $left = $i === 0 ? true : $checkValue($action[$i - 1]);

                        if (!$left) {
                            $val = false;
                        } else {
                            $val = $checkValue($action[$i + 1]);
                        }

                        if ($i === 0) {
                            array_splice($action, 0, 2, $val);
                        } else {
                            array_splice($action, $i - 1, 3, $val);
                        }

                        $i--;
                        break;
                    case '||':
                        $left = $i === 0 ? false : $checkValue($action[$i - 1]);

                        if ($left) {
                            $val = true;
                        } else {
                            $val = $checkValue($action[$i + 1]);
                        }

                        if ($i === 0) {
                            array_splice($action, 0, 2, $val);
                        } else {
                            array_splice($action, $i - 1, 3, $val);
                        }

                        $i--;
                }
                $i++;
            }
            if (count($action) !== 1) {
                throw new errorException('Ошибка вычисления cond:' . $string);
            }
            return $checkValue($action[0]);
        };
        return $calcIt($pack_Sc($actions));
    }

    protected function getMathFromString($string)
    {
        $string = preg_replace('/\s+/', '', $string);

        $actions = preg_split(
            '`((?<=[^(+\-^*/])[()+\-^*/]|[(])`',
            $string,
            null,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $pack_Sc = function ($actions) use (&$pack_Sc, &$calcIt) {
            $sc = 0;
            $interval_start = null;
            $i = 0;
            while ($i < count($actions)) {
                if ($actions[$i] === "") {
                    array_splice($actions, $i, 1);
                    continue;
                }
                switch ((string)$actions[$i]) {
                    case '(':
                        if ($sc++ == 0) {
                            $interval_start = $i;
                        }
                        break;
                    case ')':
                        if ($sc < 1) {
                            throw new errorException('Непарная закрывающая скобка');
                        }
                        if (--$sc == 0) {
                            array_splice(
                                $actions,
                                $interval_start,
                                $i + 1 - $interval_start,
                                $calcIt($pack_Sc(array_slice(
                                    $actions,
                                    $interval_start + 1,
                                    $i - $interval_start - 1
                                )))
                            );
                            $i = $interval_start - 1;
                        }
                        break;
                }
                $i++;
            }
            return $actions;
        };

        $checkValue = function ($var) {
            if ($var && !is_numeric($var)) {
                $var = $this->execSubCode($var, 'MathCode');
            }
            return $var;
        };

        $calcIt = function ($action) use ($checkValue, $string) {
            $i = 0;
            while ($i < count($action)) {
                switch ((string)$action[$i]) {
                    case '^':
                        $left = $checkValue($action[$i - 1]);
                        $right = $checkValue($action[$i + 1]);
                        $val = $this->operatorExec($action[$i], $left, $right);
                        array_splice($action, $i - 1, 3, $val);
                        $i--;
                }
                $i++;
            }

            $i = 0;
            while ($i < count($action)) {
                switch ((string)$action[$i]) {
                    case '/':
                    case '*':
                        $left = $checkValue($action[$i - 1]);
                        $right = $checkValue($action[$i + 1]);
                        $val = $this->operatorExec($action[$i], $left, $right);
                        array_splice($action, $i - 1, 3, $val);
                        $i--;
                }
                $i++;
            }

            $i = 0;

            while ($i < count($action)) {
                switch ((string)$action[$i]) {
                    case '+':
                    case '-':
                        $left = $i === 0 ? 0 : $checkValue($action[$i - 1]);
                        $right = $checkValue($action[$i + 1]);

                        $val = $this->operatorExec($action[$i], $left, $right);

                        if ($i === 0) {
                            array_splice($action, 0, 2, $val);
                        } else {
                            array_splice($action, $i - 1, 3, $val);
                        }
                        $i--;
                }
                $i++;
            }
            if (count($action) !== 1 || !is_numeric((string)$action[0])) {
                throw new errorException('Ошибка вычисления математической формулы:' . $string);
            }
            return $action[0];
        };
        return $calcIt($pack_Sc($actions));
    }

    protected function getSourceTable($params)
    {
        $tableRow = $this->__checkTableIdOrName($params['table'], 'table', 'reCalculate');

        switch ($tableRow['type']) {
            case 'calcs':
                if (empty($params['cycle'])) {
                    throw new errorException('Параметр [[cycle]] не указан');
                }

                $Cycle = $this->Table->getMir()->getCycle($params['cycle'], $tableRow['tree_node_id']);
                $table = $Cycle->getTable($tableRow);
                unset($params['cycle']);

                break;
            case 'tmp':
                if (empty($params['hash']) && $this->Table->getTableRow()['name'] != $tableRow['name']) {
                    throw new errorException('Параметр [[hash]] не указан');
                }
                if (!empty($params['hash'])) {
                    $table = $this->Table->getMir()->getTable($tableRow, $params['hash'] ?? null);
                } else {
                    $table = $this->Table;
                }
                break;
            default:
                $table = $this->Table->getMir()->getTable($tableRow);

                break;
        }

        $table->addCalculateLogInstance($this->Table->getCalculateLog());

        return $table;
    }

    protected function parseMirJson(string $str)
    {
        $r = json_decode($str, true);
        if (json_last_error() && ($error = json_last_error_msg())) {
            try {
                $TJ = new MirJson($str);
                $TJ->setMirCalculate(function ($param) {
                    return $this->execSubCode($param, 'paramFromJson');
                });
                $TJ->setStringCalculate(function ($str) {
                    if (key_exists($str, $this->CodeStrings)) {
                        return substr($this->CodeStrings[$str], 1);
                    } else {
                        return $str;
                    }
                });
                $TJ->parse();
            } catch (\Exception $e) {
                throw new errorException($e->getMessage());
            }

            return $TJ->getJson();
        } else {
            return $r;
        }
    }

    protected function getEnvironment(): array
    {
        $env = [
            'table' => $this->Table->getTableRow()['name']
        ];
        switch ($this->Table->getTableRow()['type']) {
            case 'calcs':
                $env['cycle_id'] = $this->Table->getCycle()->getId();
                break;
            case 'tmp':
                $env['cycle_id'] = $this->Table->getTableRow()['sess_hash'];
                break;
        }

        if (!empty($this->row['id'])) {
            $env['id'] = $this->row['id'];
        }

        return $env;
    }

    protected function parseMirStr($string): string
    {
        $string = preg_replace('/\s+/', '', $string);
        $result = "";

        foreach (explode('+', $string) as $i => $part) {
            if ($part === '') {
                $result .= " ";
            } else {
                $result .= $this->execSubCode($part, 'part' . $i);
            }
        }
        return $result;
    }


    protected function funcGetFromScript($params)
    {
        $params = $this->getParamsArray($params, ['post'], ['post']);

        if (empty($params['uri']) || !preg_match(
            '`https?://`',
            $params['uri']
        )) {
            throw new errorException('Параметр uri обязателен и должен начитаться с http/https');
        }

        $link = $params['uri'];
        if (!empty($params['post'])) {
            $post = $this->__getActionFields($params['post'], 'GetFromScript');
        } elseif (!empty($params['posts'])) {
            $post = $params['posts'];
        } else {
            $post = null;
        }


        if (!empty($params['gets'])) {
            $link .= strpos($link, '?') === false ? '?' : '&';
            $link .= http_build_query($params['gets']);
        }

        $toBfl = $params['bfl'] ?? in_array(
            'script',
            $this->Table->getMir()->getConfig()->getSettings('bfl') ?? []
        );

        try {
            $r = $this->cURL(
                $link,
                'http://' . $this->Table->getMir()->getConfig()->getFullHostName(),
                $params['header'] ?? 0,
                $params['cookie'] ?? '',
                $post,
                (($params['ssh'] ?? false) ? 'parallel' : $params['timeout'] ?? null),
                ($params['headers'] ?? ""),
                ($params['method'] ?? ""),
            );
            if ($toBfl) {
                $this->Table->getMir()->getOutersLogger()->error(
                    "getFromScript",
                    [
                        'link' => $link,
                        'ref' => 'http://' . $this->Table->getMir()->getConfig()->getFullHostName(),
                        'header' => $params['header'] ?? 0,
                        'headers' => $params['headers'] ?? 0,
                        'cookie' => $params['cookie'] ?? '',
                        'post' => $post,
                        'timeout' => ($params['timeout'] ?? null),
                        'result' => mb_check_encoding($r, 'utf-8') ? $r : base64_encode($r)
                    ]
                );
            }
            return $r;
        } catch (\Exception $e) {
            if ($toBfl) {
                $this->Table->getMir()->getOutersLogger()->error(
                    "getFromScript:",
                    ['error' => $e->getMessage()] + [
                        'link' => $link,
                        'ref' => 'http://' . $this->Table->getMir()->getConfig()->getFullHostName(),
                        'header' => $params['header'] ?? 0,
                        'headers' => $params['headers'] ?? 0,
                        'cookie' => $params['cookie'] ?? '',
                        'post' => $post,
                        'timeout' => ($params['timeout'] ?? null),
                        'result' => mb_check_encoding($r, 'utf-8') ? $r : base64_encode($r)
                    ]
                );
            }
            throw new errorException($e->getMessage());
        }
    }

    protected function funcGlobVar($params)
    {
        $params = $this->getParamsArray($params, [], []);
        if (empty($params['name'])) {
            throw new errorException('Параметр  name должен быть заполнен');
        }
        $_params = [];
        if (key_exists('value', $params)) {
            $_params['value'] = $params['value'];
        } elseif (key_exists('default', $params)) {
            $_params['default'] = $params['default'];
        } elseif (key_exists('block', $params)) {
            $_params['block'] = $params['block'];
        }
        if ($params['date'] ?? false) {
            $_params['date'] = true;
        }

        return $this->Table->getMir()->getConfig()->globVar($params['name'], $_params);
    }

    protected function funcProcVar($params)
    {
        $params = $this->getParamsArray($params, [], []);
        if (empty($params['name'])) {
            throw new errorException('Параметр  name должен быть заполнен');
        }
        $_params = [];
        if (key_exists('value', $params)) {
            $_params['value'] = $params['value'];
        } elseif (key_exists('default', $params)) {
            $_params['default'] = $params['default'];
        }

        return $this->Table->getMir()->getConfig()->procVar($params['name'], $_params);
    }

    protected function __getActionFields($fieldParams, $funcName)
    {
        $fields = [];

        if (empty($fieldParams)) {
            return false;
        }
        foreach ($fieldParams as $f) {
            $fc = $this->getCodes($f);

            try {
                if (count($fc) < 2) {
                    throw new \Exception();
                }


                $fieldName = $this->__getValue($fc[0]);
                if (empty($fieldName)) {
                    throw new \Exception();
                }


                $fieldValue = $this->__getValue($fc[2] ?? $fc[1]);

                if (in_array(strtolower($funcName), ['set', 'setlist', 'setlistextended'])) {
                    if ($fc[1]['type'] === 'operator') {
                        $percent = $fc[2]['percent'] ?? false;
                        $fieldValue = new FieldModifyItem($fc[1]['operator'], $fieldValue, $percent);
                    } elseif (empty($fc['comparison'])) {
                        $fieldValue = new FieldModifyItem('+', $fieldValue, $fc[1]['percent'] ?? false);
                    }
                }

                //if (is_null($fieldValue)) throw new Exception();
            } catch (errorException $e) {
                $e->addPath('[[' . $funcName . ']] field [[' . $this->getReadCodeForLog($f) . ']]');
                throw $e;
            } catch (SqlException $e) {
                throw $e;

                throw new errorException($e->getMessage() . ' [[' . $funcName . ']] field [[' . $this->getReadCodeForLog($f) . ']]');
            } catch (\Exception $e) {
                throw new errorException('Неправильное оформление кода в [[' . $funcName . ']] field [[' . $this->getReadCodeForLog($f) . ']]');
            }
            $fields[$fieldName] = $fieldValue;
        }
        return $fields;
    }

    /**
     * @param $url
     * @param string $ref
     * @param int $header
     * @param string $cookie
     * @param null $post
     * @param int|"parallel"|null $timeout
     * @param null $headers
     * @param null $method
     * @return bool|string
     * @throws errorException
     */
    public static function cURL($url, $ref = '', $header = 0, $cookie = '', $post = null, $timeout = null, $headers = null, $method = null)
    {
        if ($headers) {
            $headers = (array)$headers;
        } else {
            $headers = [];
        }
        if ($cookie) {
            $headers[] = "Cookie: " . $cookie;
        }

        if ($timeout === "parallel") {
            $data = "";
            if (empty($method)) {
                $method = null;
            }
            $localeOld = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, "en_US.UTF-8");
            if (!is_null($post)) {
                $method = $method ?? "POST";
                if (!empty($post)) {
                    $post = is_array($post) ? http_build_query($post) : $post;
                    $data = '--data ' . escapeshellarg($post);
                }
            } else {
                $method = $method ?? "GET";
            }

            if ($ref) {
                $ref = '--referer ' . escapeshellarg($ref);
            }

            $hhs = [];
            foreach ($headers ?? [] as $h) {
                $hhs[] = '-H ' . escapeshellarg($h);
            }

            setlocale(LC_CTYPE, $localeOld);

            $hhs = implode(' ', $hhs);
            `curl --insecure --request $method $ref $hhs $url $data  > /dev/null 2>&1 &`;

            return null;
        }


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($ch, CURLOPT_REFERER, $ref);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, $header);

        if ($timeout) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }

        if (!empty($method)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (!is_null($post)) {
            if (empty($method)) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POST, 1);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
        }


        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, (array)$headers);
        }

        $result = curl_exec($ch);
        if ($error = curl_error($ch)) {
            curl_close($ch);
            throw new errorException($error);
        }
        curl_close($ch);
        return $result;
    }
}
