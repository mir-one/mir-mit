<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 2018-12-21
 * Time: 14:19
 */

namespace mir\moduls\install;

use Psr\Http\Message\ServerRequestInterface;
use mir\common\controllers\interfaceController;
use mir\common\MirInstall;

class installController extends interfaceController
{
    public static $pageTemplate = 'page_install_template.php';

    public function __construct()
    {
    }

    public function actionMain(ServerRequestInterface $serverRequest)
    {
        $post = $serverRequest->getParsedBody();
        set_time_limit(120);
        $done=false;
        if (!empty($post)) {
            try {
                $post['schema_exists'] = $post['schema_exists'] === '1';

                $MirInstall = new MirInstall($post, $post['user_login']);
                $MirInstall->install(function ($file) {
                    return dirname(__FILE__) . DIRECTORY_SEPARATOR . $file;
                });
                $done=true;
            } catch (\Exception $exception) {
                $this->__addAnswerVar('error', $exception->getMessage() . "\n\n" . $exception->getTraceAsString());
                if (!empty($Sql)) {
                    $Sql->transactionRollBack();
                }
            }
        }
        if ($done) {
            static::$contentTemplate = dirname(__FILE__) . DIRECTORY_SEPARATOR . '__done.php';
        } else {
            static::$contentTemplate = dirname(__FILE__) . DIRECTORY_SEPARATOR . '__form.php';
        }
    }


    public function doIt(ServerRequestInterface $request, bool $output)
    {
        $this->actionMain($request);
        if ($output) {
            $this->output('Main');
        }
    }

    public function outputHtmlTemplate()
    {
        extract($this->answerVars);

        include dirname(__FILE__) . DIRECTORY_SEPARATOR . 'page_install_template.php';
    }
}
