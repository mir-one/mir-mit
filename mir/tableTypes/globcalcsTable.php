<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 21.03.17
 * Time: 10:03
 */

namespace mir\tableTypes;

use mir\common\Controller;
use mir\common\errorException;
use mir\models\NonProjectCalcs;
use mir\models\Table;

class globcalcsTable extends JsonTables
{
    public function saveTable()
    {
        if ($this->savedUpdated === $this->updated) {
            return;
        }

        /*if(empty($GLOBALS['test'])){
            $GLOBALS['test']=1;
            errorException::tableUpdatedException($this);
        }
*/

        $updateWhere = [
            'tbl_name' => $this->tableRow['name']
        ];
        if ($this->getTableRow()['actual'] !== 'disable') {
            $updateWhere['updated'] = $this->savedUpdated;
        }

        if (!$this->model->update(
            [
            'tbl' => $this->getPreparedTbl(),
            'updated' => $this->updated
        ],
            $updateWhere
        )
        ) {
            errorException::tableUpdatedException($this);
        }
        $saved = $this->savedTbl;
        $this->savedTbl = $this->tbl;
        $this->savedUpdated = $this->updated;
        $this->setIsTableDataChanged(false);

        $this->onSaveTable($this->tbl, $saved);

        $this->Mir->tableChanged($this->tableRow['name']);
        return true;
    }

    public function createTable()
    {
        $this->model->insertPrepared(['tbl_name' => $this->tableRow['name'], 'updated' => $updated = $this->getUpdatedJson()]);
        $this->savedUpdated = $this->updated = $updated;
    }

    public function loadDataRow($fromConstructor = false, $force = false)
    {
        if (empty($this->dataRow) || $force) {
            $this->dataRow = $this->model->getById($this->tableRow['name']);
            $this->tbl = json_decode($this->dataRow['tbl'] ?? '[]', true);

            $this->indexRows();
            $this->loadedTbl = $this->savedTbl = $this->tbl;
        }
    }


    protected function loadModel()
    {
        $this->model = $this->Mir->getNamedModel(NonProjectCalcs::class);
    }

    public function getLastUpdated($force = false)
    {
        if ($force) {
            return $this->model->getById($this->tableRow['name'], 'updated')['updated'];
        }
        return $this->updated;
    }
}
