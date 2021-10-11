<?php

namespace mir\common\sql;

use Exception;
use \PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;

class Sql
{

    /**
     * @var PDO
     */
    protected $PDO;
    protected $lastQuery = ["str" => null, "error" => null, 'num' => 0];
    protected $onCommit = [];
    protected $isRollbacked = false;
    protected $startedTransactionsCounter = 0;
    /**
     * @var LoggerInterface
     */
    private $Log;
    protected $preparedCount;


    public function __construct(array $settings, LoggerInterface $Log, $withSchema = true)
    {
        $this->Log = $Log;
        $this->PDO = static::getNewConnection($settings, $withSchema);
    }

    /**
     * Simple PDO
     * No logging
     * Not use if you can
     *
     * @return PDO
     */
    public function getPDO()
    {
        if ($this->isRollbacked) {
            $this->Log->error('Try to get rollbacked SQL', debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
            throw new SqlException('Transaction was rollbacked. Create new Conf and Mir for correct work with MIR');
        }
        return $this->PDO;
    }

    public function __destruct()
    {
        if (!$this->isRollbacked) {
            $this->transactionRollBack();
        }
    }

    public function addOnCommit($func)
    {
        $this->onCommit[] = $func;
    }

    public function insert($table, $vars, $returning = null, $ignore = false)
    {
        $vars2 = array();
        foreach ($vars as $k => $v) {
            $vars2['"' . $k . '"'] =
                (
                is_null($v) || $v === '[[NULL]]' ? 'null' :
                    (
                    is_bool($v) ?
                        ($v === false ? 'false' : 'true')
                        :
                        $this->quote($v)
                    )
                );
        }
        if ($vars2) {
            $query_string = 'insert into ' . $table . ' (' . implode(
                    ',',
                    array_keys($vars2)
                ) . ') VALUES (' . implode(
                    ',',
                    array_values($vars2)
                ) . ') '
                . ($ignore ? ' ON CONFLICT DO NOTHING ' : '')
                . ($returning ? ' RETURNING ' . $returning : '');
        } else {
            $query_string = 'insert into ' . $table . ' DEFAULT VALUES'
                . ($ignore ? ' ON CONFLICT DO NOTHING ' : '')
                . ($returning ? ' RETURNING ' . $returning : '');
        }

        if ($returning) {
            return $this->getField($query_string);
        } else {
            return $this->exec($query_string);
        }
    }


    public function quote($var, $isMastBeInteger = false)
    {
        if (is_array($var)) {
            return

                array_map(
                    function ($v) use ($isMastBeInteger) {
                        if (is_array($v)) {
                            debug_print_backtrace();
                        }
                        return $this->quote($v, $isMastBeInteger);
                    },
                    $var
                );
        }
        if ($isMastBeInteger) {
            if (!ctype_digit($strVar = strval($var))) {
                if (!is_numeric($strVar)) {
                    return 'NULL';
                }
                return $this->getPDO()->quote($var) . '::NUMERIC';
            }
        }

        if (is_bool($var)) {
            return "'" . ($var ? 'true' : 'false') . "'";
        }

        return $this->getPDO()->quote($var);
    }

    public function getField($query_string, $vars = array())
    {
        $query_string = $this->getQueryString($query_string, $vars);
        $r = $this->query($query_string);
        return $r->fetchColumn();
    }

    public function exec($query_string, $vars = array(), $execForce = false)
    {
        $query_string = $this->getQueryString($query_string, $vars);
        $r = $this->query($query_string, $execForce);
        if (!$execForce) {
            return $r->rowCount();
        }
    }

    public function get($query_string, $vars = array())
    {
        $query_string = $this->getQueryString($query_string, $vars);
        $r = $this->query($query_string);
        return $r->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll($query_string, $fetchType = PDO::FETCH_ASSOC)
    {
        $query_string = $this->getQueryString($query_string);
        return $this->query($query_string)->fetchAll($fetchType);
    }

    public function getFieldArray($query_string, $vars = array())
    {
        $query_string = $this->getQueryString($query_string, $vars);
        return $this->query($query_string)->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function transactionStart()
    {
        $this->Log->debug(
            'start_transaction:' . $this->startedTransactionsCounter,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        );

        if (!$this->startedTransactionsCounter) {
            $this->exec('START TRANSACTION');
        }
        $this->startedTransactionsCounter++;
        return $this->startedTransactionsCounter;
    }

    public function transactionCommit()
    {
        $this->startedTransactionsCounter--;
        $this->Log->debug('commit:' . $this->startedTransactionsCounter);
        if (!$this->startedTransactionsCounter) {
            foreach ($this->onCommit as $func) {
                $func();
            }
            $this->exec('COMMIT');
            return true;
        }
        return false;
    }

    public function transactionRollBack()
    {
        if ($this->startedTransactionsCounter > 0) {
            $this->startedTransactionsCounter = 0;
            $this->exec('ROLLBACK');
            $this->isRollbacked = true;
            try {
                $this->Log->debug('SELECT pg_terminate_backend(pg_backend_pid())');
                $this->PDO->exec('SELECT pg_terminate_backend(pg_backend_pid())');
            } catch (\PDOException $e) {
            }
        }
    }

    public function getLastQueryString()
    {
        return $this->lastQuery["str"];
    }

    public function getLastQueryError()
    {
        return $this->lastQuery["error"];
    }

    public function getLastQueryNum()
    {
        return $this->lastQuery["num"];
    }

    public static function quoteFields($var)
    {
        if (is_array($var)) {
            return

                array_map(
                    function ($v) {
                        if (is_array($v)) {
                            debug_print_backtrace();
                        }
                        return static::quoteFields($v);
                    },
                    $var
                );
        }

        return '"' . preg_replace('/[^A-Za-z0-9_]+/', '', $var) . '"';
    }


    protected function getQueryString($query_string, $vars = null)
    {
        if ($vars) {
            $vars2 = array();
            foreach ($vars as $k => $v) {
                if (is_array($v)) {
                    $_vv = array();
                    foreach ($v as $_v) {
                        $_vv[] = (is_null($_v) ? 'NULL' : $this->quote($_v));
                    }
                    $vars2["[$k]"] = '(' . implode(',', $_vv) . ')';
                } else {
                    $vars2["[$k]"] = (is_null($v) ? 'NULL' : $this->quote($v));
                }
            }
            $vars = $vars2;
            $query_string = str_replace(array_keys($vars), array_values($vars), $query_string);
        }
        return $query_string;
    }

    protected function query($query_string, $exec = false)
    {
        $this->lastQuery = ['str' => $query_string, 'error' => null, 'num' => ($this->lastQuery['num'] + 1)];

        $microTime = microtime(1);
        if ($exec) {
            $r = $this->getPDO()->exec($query_string);
            if ($this->PDO->errorInfo()[0] !== '00000') {
                $this->errorHandler($query_string);
            }
        } else {
            $r = $this->getPDO()->query($query_string);
            if (!$r) {
                $this->errorHandler($query_string);
            }
        }

        $query_time_pad = str_pad(round(microtime(1) - $microTime, 3), 5, '0', STR_PAD_LEFT);

        $this->lastQuery['time'] = $query_time_pad;
        $this->lastQuery['rows'] = $r ? $r->rowCount() : null;

        $this->Log->debug($query_time_pad . ' !(' . $this->lastQuery['rows'] . ' rows) >> ' . $query_string);
        return $r;
    }

    /**
     * @param $query_string
     * @param null $error
     * @param null $data
     * @throws \mir\common\sql\SqlException
     */
    public function errorHandler($query_string, $error = null, $data = null)
    {
        $error = $error ?? $this->PDO->errorInfo();
        $error = $error[2] ?? "Ошибка POSTGRES: CODE " . $error[0];

        if ($error) {
            $this->Log->error(
                $error,
                (!is_null($data) ? ['vars' => $data] : []) + ['query' => $query_string, 'debug' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)]
            );
            $this->lastQuery['error'] = $error;

            $exp = new SqlException($error);
            $exp->addPath($query_string);
            $exp->addPath(json_encode($data, JSON_UNESCAPED_UNICODE));
            throw $exp;
        }
    }

    public function getPrepared($query_string, $driver_options = []): PDOStatement
    {
        $this->lastQuery = ['str' => $query_string, 'error' => null, 'num' => ($this->lastQuery['num'] + 1), 'options'=>$driver_options];
        $microTime = microtime(1);
        $stmt = $this->getPDO()->prepare($query_string, $driver_options);

        if (!$stmt) {
            $this->errorHandler($query_string);
        }

        $query_time_pad = str_pad(round(microtime(1) - $microTime, 3), 5, '0', STR_PAD_LEFT);

        $this->lastQuery['time'] = $query_time_pad;
        $this->lastQuery['rows'] = '(prep)';

        $stmt->num = ++$this->preparedCount;
        $this->Log->debug($query_time_pad . ' (prep' . $stmt->num . ') >> ' . $query_string);

        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        return $stmt;
    }

    public function executePrepared(PDOStatement $statement, array $listOfParams)
    {
        $this->lastQuery = ['str' => $statement->queryString, 'error' => null, 'num' => ($this->lastQuery['num'] + 1), 'options'=>$listOfParams];

        $microTime = microtime(true);
        foreach ($listOfParams as &$param) {
            if (is_bool($param)) {
                $param = $param ? 'true' : 'false';
            }
        }
        unset($param);
        $r = $statement->execute($listOfParams);
        if (!$r || $statement->errorCode() !== "00000") {
            $info = $statement->errorInfo();
            $info[2] = "(prep{$statement->num}) {$info[2]}";
            $this->errorHandler($statement->queryString, $info, $listOfParams);
        }

        $query_time_pad = str_pad(round(microtime(1) - $microTime, 3), 5, '0', STR_PAD_LEFT);
        $this->lastQuery['time'] = $query_time_pad;
        $rowCount = $statement->rowCount();
        $this->lastQuery['rows'] = $rowCount;
        $this->Log->debug(
            $query_time_pad . ' (' . $statement->rowCount() . ' rows) >> (prep' . $statement->num . ')',
            $listOfParams
        );
        return $rowCount;
    }

    protected function getNewConnection($conf, $withSchema = true)
    {
        try {
            $PDO = new PDO($conf['dsn'], $conf['username'], $conf['password'], [PDO::ATTR_EMULATE_PREPARES => false]);
            if ($PDO->errorInfo()[0] !== '00000') {
                throw new SqlException($PDO->errorInfo()[2]);
            }
            if ($withSchema) {
                if (empty($conf['schema'])) {
                    throw new SqlException('Не определена схема');
                }
                $this->Log->debug('SET search_path TO "' . $conf['schema'] . '"');
                $PDO->exec('SET search_path TO "' . $conf['schema'] . '"');

                if ($PDO->errorInfo()[0] !== '00000') {
                    throw new Exception($PDO->errorInfo()[2]);
                }
            }
        } catch (Exception $e) {
            throw new SqlException('Ошибка подключения к базе данных . Попробуйте позже:' . $e->getMessage(), 0, $e);
        }
        return $PDO;
    }
}
