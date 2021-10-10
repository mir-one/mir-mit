<?php


namespace mir\moduls\An;

use Psr\Http\Message\ServerRequestInterface;
use mir\common\Auth;
use mir\common\controllers\interfaceController;
use mir\common\criticalErrorException;
use mir\common\Crypt;
use mir\common\errorException;
use mir\common\Mir;
use mir\config\Conf;
use mir\moduls\Table\ReadTableActions;
use mir\moduls\Table\WriteTableActions;

class AnController extends interfaceController
{
    public static $pageTemplate = 'page_template_simple.php';

    protected $Table;
    protected $onlyRead;
    /**
     * @var ServerRequestInterface
     */
    private $Request;
    /**
     * @var false|string
     */
    private $requestTable;

    public function __construct(Conf $Config, $mirPrefix = '')
    {
        parent::__construct($Config, $mirPrefix);
        static::$contentTemplate = $this->folder . '/__Main.php';
        $this->User = Auth::loadAuthUserByLogin($Config, "anonym", false);
        if (!$this->User) {
            die('Пользователь для анонимных таблиц не подключен');
        }
        $this->Mir = new Mir($Config, $this->User);
    }

    public function doIt(ServerRequestInterface $request, bool $output)
    {
        $this->Request = $request;
        $requestUri = preg_replace('/\?.*/', '', $request->getUri()->getPath());
        $this->requestTable = substr($requestUri, 2 + strlen($this->Config->getAnonymModul()));


        $post = ($request->getParsedBody());
        $this->checkTableByUri($request);


        if ($post['ajax'] ?? null) {
            $this->isAjax = true;
        }
        if ($this->requestTable) {
            if (!$this->isAjax) {
                $action = 'Main';
            } else {
                $action = 'Actions';
            }
        } else {
            $action = 'Main';
        }
        try {
            if ($this->isAjax) {
                $action = 'Ajax' . $action;
            }
            $this->__run($action, $request);
        } catch (\Exception $e) {
            if (!$this->isAjax) {
                static::$contentTemplate = $this->Config::getTemplatesDir() . '/__error.php';
            }
            $message = $e->getMessage();

            $this->__addAnswerVar('error', $message);
        }
        $this->output($action);
    }

    public function actionAjaxActions()
    {
        if (!$this->Table) {
            return $this->answerVars['error'] ?? 'Таблица не найдена';
        }

        $this->Mir->transactionStart();

        try {
            if (!($method = $this->Request->getParsedBody()['method'] ?? '')) {
                throw new errorException('Ошибка. Не указан метод');
            }

            $Actions = $this->getTableActions($this->Request, $method);

            /** @var string $method */
            $result = $Actions->$method();

            if ($links = $this->Mir->getInterfaceLinks()) {
                $result['links'] = $links;
            }
            if ($panels = $this->Mir->getPanelLinks()) {
                $result['panels'] = $panels;
            }
            if ($links = $this->Mir->getInterfaceDatas()) {
                $result['interfaceDatas'] = $links;
            }
            $this->Mir->transactionCommit();
        } catch (errorException $exception) {
            $result = ['error' => $exception->getMessage() . ($this->User->isCreator() ? "<br/>" . $exception->getPathMess() : '')];
        }
        return $result;
    }

    public function actionMain($request)
    {
        if (!$this->Table) {
            return;
        }
        try {
            $Actions = $this->getTableActions($this->Request, "getFullTableData");
            $result = $Actions->getFullTableData(true);
        } catch (criticalErrorException $exception) {
            $this->clearMir($request);
            $Actions = $this->getTableActions($this->Request, "getFullTableData");
            $error = $exception->getMessage();
            $result = $Actions->getFullTableData(false);
        }

        $result['isMain'] = true;
        $result['isAnonim'] = true;

        $this->__addAnswerVar('error', $result['error'] ?? null);
        $this->__addAnswerVar('tableConfig', $result);
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $method
     * @return ReadTableActions|WriteTableActions
     * @throws errorException
     */
    protected function getTableActions(ServerRequestInterface $request, string $method)
    {
        if (!$this->onlyRead) {
            $Actions = new WriteTableActions($request, $this->modulePath, $this->Table, null);
            $error = 'Метод [[' . $method . ']] в этом модуле не определен или имеет админский уровень доступа';
        } else {
            $Actions = new ReadTableActions($request, $this->modulePath, $this->Table, null);
            $error = 'Ваш доступ к этой таблице - только на чтение. Обратитесь к администратору для внесения изменений';
        }

        if (!is_callable([$Actions, $method])) {
            throw new errorException($error);
        }
        return $Actions;
    }

    protected function checkTableByUri(ServerRequestInterface $request)
    {
        $requestTable = preg_replace('/\?.*/', '', $this->requestTable);

        if ($tableId = $requestTable) {
            if (!array_key_exists($tableId, $this->User->getTables())) {
                $this->__addAnswerVar('error', 'Доступ к таблице запрещен');
            } else {
                $tableRow = $this->Mir->getTableRow($tableId);
                $extradata = null;
                if ($tableRow['type'] !== 'tmp') {
                    $this->__addAnswerVar('error', 'Доступ через модуль только для временных таблиц');
                } else {
                    $this->onlyRead = $this->User->getTables()[$tableId] === 0;
                    if ($this->isAjax && empty($this->Request->getParsedBody()['tableData']['sess_hash'] ?? null)) {
                        $this->__addAnswerVar('error', 'Ошибка доступа к таблице');
                    } else {
                        $extradata = $this->Request->getParsedBody()['tableData']['sess_hash'] ?? $_GET['sess_hash'] ?? null;
                        $this->Table = $this->Mir->getTable($tableRow, $extradata);
                        if (!$this->isAjax && !$extradata) {
                            $add_tbl_data = [];
                            $add_tbl_data["params"] = [];
                            $add_tbl_data["tbl"] = [];
                            if (key_exists('h_get', $this->Table->getFields())) {
                                $add_tbl_data["params"]['h_get'] = $request->getQueryParams();
                            }
                            if (key_exists('h_post', $this->Table->getFields())) {
                                $add_tbl_data["params"]['h_post'] = $request->getParsedBody();
                            }
                            if (key_exists('h_input', $this->Table->getFields())) {
                                $add_tbl_data["params"]['h_input'] = (string)$request->getBody();
                            }
                            if (!empty($d = ($this->Request->getQueryParams()['d']??null)) && ($d = Crypt::getDeCrypted(
                                $d,
                                $this->Config->getCryptSolt()
                            )) && ($d = json_decode($d, true))) {
                                if (!empty($d['d'])) {
                                    $add_tbl_data["tbl"] = $d['d'];
                                }
                                if (!empty($d['p'])) {
                                    $add_tbl_data["params"] = $d['p'] + $add_tbl_data["params"];
                                }
                            }
                            if ($add_tbl_data) {
                                $this->Table->addData($add_tbl_data);
                            }
                        }
                    }
                }
            }
        } else {
            $this->__addAnswerVar('error', 'Неверный путь к таблице');
        }
    }

    protected function clearMir($request): void
    {
        $this->Config = $this->Config->getClearConf();
        $this->Mir = new Mir($this->Config, $this->User);
        $this->checkTableByUri($request);
    }
}
