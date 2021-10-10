<?php


namespace mir\moduls\Table;

use Psr\Http\Message\ServerRequestInterface;
use mir\common\Auth;
use mir\common\calculates\CalculateAction;
use mir\common\calculates\CalculcateFormat;
use mir\common\errorException;
use mir\common\Model;
use mir\common\Mir;
use mir\common\User;
use mir\models\TablesFields;
use mir\tableTypes\aTable;

class Actions
{
    /**
     * @var aTable
     */
    protected $Table;
    /**
     * @var Mir
     */
    protected $Mir;
    /**
     * @var User|null
     */
    protected $User;
    /**
     * @var array|object|null
     */
    protected $post;
    /**
     * @var ServerRequestInterface
     */
    protected $Request;

    protected $modulePath;

    public function __construct(ServerRequestInterface $Request, string $modulePath, aTable $Table = null, Mir $Mir = null)
    {
        if ($this->Table = $Table) {
            $this->Mir = $this->Table->getMir();
        } else {
            $this->Mir = $Mir;
        }
        $this->User = $this->Mir->getUser();
        $this->Request = $Request;
        $this->post = $Request->getParsedBody();

        if(!empty($this->post['restoreView'])){
            $this->Table->setRestoreView(true);
        }

        $this->modulePath = $modulePath;
    }

    public function reuser()
    {
        if (!Auth::isCanBeOnShadow($this->User)) {
            throw new errorException('Функция вам недоступна');
        }
        $user = Auth::getUsersForShadow($this->Mir->getConfig(), $this->User, $this->post['userId']);
        if (!$user) {
            throw new errorException('Пользователь не найден');
        }
        Auth::asUser($this->Mir->getConfig(), $user[0]['id'], $this->User);

        $this->Mir->addToInterfaceLink($this->Request->getParsedBody()['location'], 'self', 'reload');

        return ['ok' => 1];
    }

    public function getNotificationsTable()
    {
        $Calc = new CalculateAction('=: linkToDataTable(table: \'ttm__manage_notifications\'; title: "Нотификации"; width: 800; height: "80vh"; refresh: false; header: true; footer: true)');
        $Calc->execAction('KOD', [], [], [], [], $this->Mir->getTable('tables'), 'exec');
    }

    public function loadUserButtons()
    {
        $result = null;
        $Table = $this->Mir->getTable('settings');
        $fieldData = $Table->getFields()['h_user_settings_buttons'] ?? null;

        if ($fieldData) {
            $clc = new CalculcateFormat($fieldData['format']);

            $result = $clc->getPanelFormat(
                'h_user_settings_buttons',
                $Table->getTbl()['params'],
                $Table->getTbl(),
                $Table
            );
        }
        return ['panelFormats' => $result];
    }

    /**
     * Клик по кнопке в панельке поля
     *
     * @throws errorException
     */
    public function userButtonsClick()
    {
        $model = $this->Mir->getModel('_tmp_tables', true);
        $key = ['table_name' => '_panelbuttons', 'user_id' => $this->User->getId(), 'hash' => $this->post['hash'] ?? null];
        if ($data = $model->getField('tbl', $key)) {
            $data = json_decode($data, true);
            foreach ($data as $row) {
                if ($row['ind'] === ($this->post['index'] ?? null)) {
                    $Table = $this->Mir->getTable('settings');
                    if (is_string($row['code']) && key_exists($row['code'], $Table->getFields())) {
                        $row['code'] = $Table->getFields()[$row['code']]['codeAction'];
                    }
                    $CA = new CalculateAction($row['code']);
                    $item = $Table->getTbl()['params'];


                    $CA->execAction(
                        $row['field'],
                        [],
                        $item,
                        [],
                        $Table->getTbl(),
                        $Table,
                        'exec',
                        $row['vars'] ?? []
                    );
                    break;
                }
            }
        } else {
            throw new errorException('Предложенный выбор устарел.');
        }
        return ['ok' => 1];
    }

    public function notificationUpdate()
    {
        if (!empty($this->post['id'])) {
            if ($rows = $this->Mir->getModel('notifications')->getAll(['id' => $this->post['id'], 'user_id' => $this->User->getId()])) {
                $upd = [];
                switch ($this->post['type']) {
                    case 'deactivate':
                        $upd = ['active' => false];
                        break;
                    case 'later':

                        $date = date_create();
                        if (empty($this->post['num']) || empty($this->post['item'])) {
                            $date->modify('+5 minutes');
                        } else {
                            $items = [1 => 'minutes', 'hours', 'days'];
                            $date->modify('+' . $this->post['num'] . ' ' . ($items[$this->post['item']] ?? 'minutes'));
                        }

                        $upd = ['active_dt_from' => $date->format('Y-m-d H:i')];
                        break;
                }

                $md = [];
                foreach ($rows as $row) {
                    $md[$row['id']] = $upd;
                }
                $this->Mir->getTable('notifications')->reCalculateFromOvers(['modify' => $md]);
            }
        }
        return ['ok' => 1];
    }

    /**
     * Клик по linkToInout
     *
     *
     * @throws errorException
     */
    public function linkInputClick()
    {
        $model = $this->Mir->getModel('_tmp_tables', true);
        $key = ['table_name' => '_linkToInput', 'user_id' => $this->User->getId(), 'hash' => $this->post['hash'] ?? null];
        if ($data = $model->getField('tbl', $key)) {
            $data = json_decode($data, true);

            if (key_exists('cycle_id', $data['env'])) {
                $Table = $this->Mir->getTable($data['env']['table'], $data['env']['cycle_id']);
            } elseif (key_exists('hash', $data['env'])) {
                $Table = $this->Mir->getTable($data['env']['table'], $data['env']['hash']);
            } else {
                $Table = $this->Mir->getTable($data['env']['table']);
            }

            $row = [];
            if (key_exists('id', $data['env'])) {
                if ($Table->loadFilteredRows('inner', [$data['env']['id']])) {
                    $row = $Table->getTbl()['rows'][$data['env']['id']];
                }
            }


            if ($Table->getFields()[$data['code']] ?? false) {
                $CA = new CalculateAction($this->Table->getFields()[$data['code']]['codeAction']);
            } else {
                $CA = new CalculateAction($data['code']);
            }

            $CA->execAction(
                'CODE',
                [],
                $row,
                $Table->getTbl(),
                $Table->getTbl(),
                $Table,
                'exec',
                ($data['vars'] ?? []) + ['input' => $this->post['val']]
            );

            $model->delete($key);
        } else {
            throw new errorException('Предложенный ввод устарел.');
        }
        return ['ok' => 1];
    }

    public function checkForNotifications()
    {
        /*TODO FOR MY TEST SERVER */
        if ($_SERVER['HTTP_HOST'] === 'localhost:8080') {
            die('test');
        }

        $actived = $this->post['activeIds'] ?? [];
        $model = $this->Mir->getModel('notifications');
        $codes = $this->Mir->getModel('notification_codes');
        $getNotification = function () use ($actived, $model, $codes) {
            if (!$actived) {
                $actived = [0];
            }
            $result = [];

            if ($row = $model->getPrepared(
                ['!id' => $actived,
                    '<=active_dt_from' => date('Y-m-d H:i:s'),
                    'user_id' => $this->User->getId(),
                    'active' => 'true'],
                '*',
                '(prioritet->>\'v\')::int, id'
            )) {
                array_walk(
                    $row,
                    function (&$v, $k) {
                        if (!Model::isServiceField($k)) {
                            $v = json_decode($v, true);
                        }
                    }
                );
                $kod = $codes->getField(
                    'code',
                    ['name' => $row['code']['v']]
                );
                $calc = new CalculateAction($kod);
                $table = $this->Mir->getTable('notifications');
                $calc->execAction(
                    'code',
                    [],
                    $row,
                    [],
                    $table->getTbl(),
                    $table,
                    'exec',
                    $row['vars']['v']
                );

                $result['notification_id'] = $row['id'];
            }
            if ($actived) {
                $result['deactivated'] = [];
                if ($ids = ($model->getColumn(
                    'id',
                    ['id' => $actived, 'user_id' => $this->User->getId(), 'active' => 'false']
                ) ?? [])) {
                    $result['deactivated'] = array_merge($result['deactivated'], $ids);
                }
                if ($ids = ($model->getColumn(
                    'id',
                    ['id' => $actived, 'user_id' => $this->User->getId(), 'active' => 'true', '>active_dt_from' => date('Y-m-d H:i')]
                ) ?? [])) {
                    $result['deactivated'] = array_merge($result['deactivated'], $ids);
                }
                if (empty($result['deactivated'])) {
                    unset($result['deactivated']);
                }
            }
            return $result;
        };

        if (!empty($this->post['periodicity']) && ($this->post['periodicity'] > 1)) {
            $i = 0;

            $count = ceil(20 / $this->post['periodicity']);

            do {
                echo "\n";
                flush();

                if (connection_status() !== CONNECTION_NORMAL) {
                    die;
                }
                if ($result = $getNotification()) {
                    break;
                }

                sleep($this->post['periodicity']);
            } while (($i++) < $count);
        } else {
            $result = $getNotification();
        }
        echo json_encode($result + ['notifications' => array_map(
            function ($n) {
                    $n[0] = 'notification';
                    return $n;
                },
            $this->Mir->getInterfaceDatas()
        )]);
        die;
    }
}
