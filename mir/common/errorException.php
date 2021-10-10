<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 11.01.17
 * Time: 13:51
 */

namespace mir\common;

use Composer\Config;
use mir\common\configs\ConfParent;
use mir\common\WithPathMessTrait;
use mir\tableTypes\aTable;

class errorException extends \Exception
{
    use WithPathMessTrait;

    public function __get($name)
    {
        return $this->$name;
    }

    public function __construct($message, $code = 0)
    {
        parent::__construct($message, $code);
    }

    public static function tableUpdatedException(aTable $aTable)
    {
        $aTable->getMir()->transactionRollback();
        throw new tableSaveException('Таблица [[' . $aTable->getTableRow()['title'] . ']] была изменена. Обновите таблицу для проведения изменений');
    }

    /**
     * @param $error
     * @param aTable|Mir|ConfParent $contextObject
     * @throws criticalErrorException
     */
    public static function criticalException($error, $contextObject = null)
    {
        switch (get_class($contextObject)) {
            case aTable::class:
                $contextObject->getMir()->transactionRollback();
                break;
            case Mir::class:
                $contextObject->transactionRollback();
                break;
            case Config::class:
                $contextObject->getSql()->transactionRollback();
                break;
        }

        throw new criticalErrorException($error);
    }
}
