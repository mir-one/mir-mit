<?php


namespace mir\moduls\install;

use mir\common\Auth;

class startAuth extends Auth
{
    protected $roleIds=["1"];

    protected function __construct($rowData)
    {
        $this->rowData=$rowData;
    }
    public static function startUser($rowData)
    {
        static::$aUser=new startAuth($rowData);
    }
    public static function isCreator()
    {
        return true;
    }
}
