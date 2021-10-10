<?php

namespace mir\common\controllers;

use Psr\Http\Message\ServerRequestInterface;
use mir\common\Mir;
use mir\config\Conf;

abstract class Controller
{
    /**
     * @var Mir
     */
    protected $Mir;

    protected $answerVars = [];
    protected $modulePath;
    protected $Config;
    /**
     * @var string
     */
    protected $mirPrefix;

    public function __construct(Conf $Config, $mirPrefix = '/')
    {
        $this->Config = $Config;
        $this->mirPrefix = $mirPrefix;
    }

    /**
     * @param ServerRequestInterface $request
     * @param bool $output
     * @return void
     */
    abstract public function doIt(ServerRequestInterface $request, bool $output);


    protected function __run($operation, ServerRequestInterface $request)
    {
        $this->__actionRun($operation, $request);
    }


    protected function __actionRun($action, ServerRequestInterface $request)
    {
        if (method_exists(get_called_class(), $action = 'action' . $action)) {
            $this->__processActionReturnData($this->$action($request));
        }
    }

    protected function __processActionReturnData($data)
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $this->__addAnswerVar($k, $v);
            }
        } elseif (is_string($data) && strlen($data) > 0) {
            $this->__addAnswerVar(
                'error',
                $data
            );
        }
    }

    protected function __addAnswerVar($name, $var)
    {
        $this->answerVars[$name] = $var;
    }
}
