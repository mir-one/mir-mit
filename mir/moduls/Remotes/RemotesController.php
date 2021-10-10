<?php


namespace mir\moduls\Remotes;

use Psr\Http\Message\ServerRequestInterface;
use mir\common\Auth;
use mir\common\calculates\CalculateAction;
use mir\common\controllers\Controller;
use mir\common\Model;
use mir\common\tableSaveException;
use mir\common\Mir;
use mir\tableTypes\RealTables;

class RemotesController extends Controller
{
    public function doIt(ServerRequestInterface $request, bool $output)
    {
        $requestUri = preg_replace('/\?.*/', '', $request->getUri()->getPath());
        $requestPath = substr($requestUri, strlen($this->mirPrefix . 'Remotes/'));

        $remoteSelect = $this->Config->getModel('ttm__remotes')->get(
            ['on_off' => 'true', 'name' => $requestPath],
            '*'
        );
        $error = null;
        $data = null;
        if ($remoteSelect) {
            $remote = Model::getClearValuesWithExtract($remoteSelect);
            $remote_row = RealTables::decodeRow($remoteSelect);
            if ($remote['remotes_user']) {
                if ($User = Auth::simpleAuth($this->Config, $remote['remotes_user'])) {

                    $tries = 0;
                    do {
                        $onceMore = false;
                        try {
                            $data = $this->action($User, $remote, $remote_row, $request);
                        } catch (tableSaveException $exception) {
                            $this->Config = $this->Config->getClearConf();
                            if (++$tries < 5) {
                                $onceMore = true;
                            } else {
                                $error = 'Ошибка одновременного доступа к таблице';
                            }
                        } catch (\Exception $e) {
                            $error = $e->getMessage();

                        }
                    } while ($onceMore);

                } else {
                    $error = 'Ошибка авторизации пользователя';
                }
            } else {
                $error = 'Remote не подключен к пользователю';
            }

            switch ($remote['return']) {
                case 'simple':
                    if ($error) {
                        echo 'error';
                    } else {
                        echo 'success';
                    }
                    break;
                case 'json':
                    if ($error) {
                        $data = ['error' => $error];
                    }
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                    break;
                case 'string':
                    echo $data;
                    break;
                default:
                    foreach ($data['headers'] as $h => $v) {
                        header($h . ':' . $v);
                    }
                    echo $data['body'];
            }
        } else {
            echo $error = 'Remote не активен или не существует';
            die;
        }
    }

    protected function action($User, $remote, $remote_row, $request)
    {
        $Mir = new Mir($this->Config, $User);
        $Mir->transactionStart();
        $table = $Mir->getTable('ttm__remotes');

        $calc = new CalculateAction($remote['code']);
        $data = $calc->execAction(
            'CODE',
            $remote_row,
            $remote_row,
            $table->getTbl(),
            $table->getTbl(),
            $table,
            'exec',
            [
                'get' => $request->getQueryParams() ?? [],
                'post' => $request->getParsedBody() ?? [],
                'input' => (string)$request->getBody(),
                'headers' => ($headers = $request->getHeaders()) ? $headers : []
            ]
        );

        $Mir->transactionCommit();
        return $data;
    }
}
