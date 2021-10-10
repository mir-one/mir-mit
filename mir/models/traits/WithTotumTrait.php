<?php


namespace mir\models\traits;

use mir\common\Mir;

trait WithMirTrait
{
    /**
     * @var Mir
     */
    protected $Mir;

    public function addMir(Mir $Mir)
    {
        $this->Mir=$Mir;
    }
}
