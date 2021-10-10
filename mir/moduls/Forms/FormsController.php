<?php


namespace mir\moduls\Forms;

use Psr\Http\Message\ServerRequestInterface;
use mir\common\Auth;
use mir\common\calculates\Calculate;
use mir\common\calculates\CalculcateFormat;
use mir\common\criticalErrorException;
use mir\common\Crypt;
use mir\common\errorException;
use mir\common\Field;
use mir\common\controllers\interfaceController;
use mir\common\tableSaveException;
use mir\common\Mir;
use mir\config\Conf;
use mir\config\mir\moduls\Forms\ReadTableActionsForms;
use mir\config\mir\moduls\Forms\WriteTableActionsForms;
use mir\fieldTypes\Select;
use mir\moduls\Table\Actions;
use mir\tableTypes\aTable;
use mir\tableTypes\tmpTable;

class FormsController extends interfaceController
{
    private static $path;

    /**
     * @var aTable
     */
    protected $Table;
    protected $onlyRead;
    private $css;
    /**
     * @var array
     */
    private $FormsTableData;
    private $_INPUT;
    private $clientFields;
    /**
     * @var array
     */
    private $sections;
    /**
     * @var CalculcateFormat
     */
    private $CalcTableFormat;
    /**
     * @var CalculcateFormat
     */
    private $CalcRowFormat;
    private $CalcFieldFormat;
    /**
     * @var Calculate
     */
    private $CalcSectionStatuses;
    /**
     * @var array|object|null
     */
    private $INPUT;
    private $mirTries = 0;

    public function __construct(Conf $Config, $mirPrefix = '')
    {
        $this->applyAllOrigins();
        parent::__construct($Config, $mirPrefix);
        static::$pageTemplate = __DIR__ . '/__template.php';
    }

    public function doIt(ServerRequestInterface $request, bool $output)
    {
        $requestUri = preg_replace('/\?.*/', '', $request->getUri()->getPath());
        $requestTable = substr($requestUri, strlen($this->modulePath));


        if ($request->getMethod() === 'GET') {
            $action = "Main";
            $this->__addAnswerVar('path', $requestTable);
        } else {
            $this->isAjax = true;

            try {
                $this->FormsTableData = $this->checkTableByStr($requestTable);
                $User = Auth::loadAuthUser($this->Config, $this->FormsTableData['call_user'], false);

                if (!$User) {
                    throw new errorException('Ошибка авторизации пользователя форм');
                }

                try {
                    $this->Mir = new Mir($this->Config, $User);
                    $this->answerVars = $this->actions($request);
                } catch (tableSaveException $exception) {
                    if (++$this->mirTries < 5) {
                        $this->Config = $this->Config->getClearConf();
                        $this->Mir = new Mir($this->Config, $User);
                        $this->answerVars = $this->actions($request);
                    } else {
                        throw new \Exception('Ошибка одновременного доступа к таблице');
                    }
                }
            } catch (\Exception $e) {
                if (!$this->isAjax) {
                    static::$contentTemplate = $this->Config->getTemplatesDir() . '/__error.php';
                }
                $message = $e->getMessage();
                $this->__addAnswerVar('error', $message);
            }
            $action = "json";
        }
        if ($output) {
            $this->output($action);
        }
    }

    protected function actions(ServerRequestInterface $request)
    {
        $this->loadTable($this->FormsTableData, $request);

        $parsedRequest = json_decode((string)$request->getBody(), true);
        try {
            if (!($method = $parsedRequest['method'] ?? '')) {
                throw new errorException('Ошибка. Не указан метод');
            }
            $Actions = $this->getTableActions($request, $method);

            if (is_callable([$Actions, 'addFormsTableData'])) {
                $Actions->addFormsTableData($this->FormsTableData);
            }

            if (!in_array($method, ['checkForNotifications', 'checkTableIsChanged'])) {
                $this->Mir->transactionStart();
            }

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
            $result = ['error' => $exception->getMessage() . ($this->Mir->getUser()->isCreator() && is_callable([$exception, 'getPathMess']) ? "<br/>" . $exception->getPathMess() : '')];
        } catch (criticalErrorException $exception) {
            $result = ['error' => $exception->getMessage() . ($this->Mir->getUser()->isCreator() && is_callable([$exception, 'getPathMess']) ? "<br/>" . $exception->getPathMess() : '')];
        }

        return $result;
    }

    public function actionMain()
    {
        $this->__addAnswerVar('css', $this->FormsTableData['css']);
    }

    protected function checkTableByStr($form)
    {
        if ($form) {
            $Mir = new Mir($this->Config, Auth::ServiceUserStart($this->Config));
            $tableData = $Mir->getTable('ttm__forms')->getByParams(
                ['where' => [
                    ['field' => 'path_code', 'operator' => '=', 'value' => $form],
                    ['field' => 'on_off', 'operator' => '=', 'value' => true]],
                    'field' => ['table_name', 'call_user', 'css', 'format_static', 'fields_else_params', 'section_statuses_code', 'field_code_formats']],
                'row'
            );

            if (!$tableData) {
                throw new errorException('Доступ к таблице запрещен');
            } else {
                return $tableData;
            }
        } else {
            throw new errorException('Неверный путь к таблице');
        }
    }

    private function loadTable($tableData, ServerRequestInterface $request)
    {
        $tableRow = $this->Mir->getTableRow($tableData['table_name']);
        if (!key_exists($tableRow['id'], $this->Mir->getUser()->getTables())) {
            throw new errorException('Ошибка настройки формы - пользователю запрещен доступ к таблице');
        }

        $extradata = null;
        $post = json_decode((string)$request->getBody(), true) ?? [];
        $extradata = $post['sess_hash'] ?? null;
        if ($tableRow['type'] === 'tmp' && $extradata) {
            if (!tmpTable::checkTableExists($tableRow['name'], $extradata, $this->Mir)) {
                $extradata = null;
            }
        }

        $this->Table = $this->Mir->getTable($tableRow, $extradata);

        $this->onlyRead = ($this->Mir->getUser()->getTables()[$this->Table->getTableRow()['id']] ?? null) !== 1;

        if (!$extradata) {
            $add_tbl_data = [];
            $add_tbl_data["params"] = [];
            if (key_exists('h_get', $this->Table->getFields())) {
                $add_tbl_data["params"]['h_get'] = $post['data']['get'] ?? [];
            }
            if (key_exists('h_post', $this->Table->getFields())) {
                $add_tbl_data["params"]['h_post'] = $post['data']['post'] ?? [];
            }
            if (key_exists('h_input', $this->Table->getFields())) {
                $add_tbl_data["params"]['h_input'] = $post['data']['input'] ?? '';
            }
            
            if (!empty($post['data']['get']['d']) && ($d = Crypt::getDeCrypted(
                $post['data']['get']['d'],
                $this->Config->getCryptSolt()
            )) && ($d = json_decode($d, true))) {
                if (!empty($d['d'])) {
                    $add_tbl_data["tbl"] = $d['d'];
                }
                if (!empty($d['p'])) {
                    $add_tbl_data["params"] = $d['p'] + $add_tbl_data["params"];
                }
            }
            if ($add_tbl_data && $this->Table->getTableRow()['type'] === 'tmp') {
                $this->Table->addData($add_tbl_data);
            }
        }
    }

    private function applyAllOrigins()
    {
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            // should do a check here to match $_SERVER['HTTP_ORIGIN'] to a
            // whitelist of safe domains
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');    // cache for 1 day
        }
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            }

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            }
            die;
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param string $method
     * @throws errorException
     */
    protected function getTableActions(ServerRequestInterface $request, string $method)
    {
        if (!$this->Table) {
            $Actions = new Actions($request, $this->modulePath, null, $this->Mir);
            $error = 'Таблица не найдена';
        } elseif (!$this->onlyRead) {
            $Actions = new WriteTableActionsForms($request, $this->modulePath, $this->Table, null);
            $error = 'Метод [[' . $method . ']] в этом модуле не определен или имеет админский уровень доступа';
        } else {
            $Actions = new ReadTableActionsForms($request, $this->modulePath, $this->Table, null);
            $error = 'Ваш доступ к этой таблице - только на чтение. Обратитесь к администратору для внесения изменений';
        }

        if (!is_callable([$Actions, $method])) {
            throw new errorException($error);
        }
        return $Actions;
    }
}
