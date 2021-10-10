<?php
/**
 * Created by PhpStorm.
 * User: tatiana
 * Date: 04.07.2018
 * Time: 11:37
 */

namespace mir\common\configs;

use mir\common\calculates\CalculateAction;
use mir\common\criticalErrorException;
use mir\common\errorException;
use mir\common\logs\Log;
use mir\common\sql\Sql;
use mir\common\sql\SqlException;
use mir\common\Mir;
use mir\fieldTypes\File;

abstract class ConfParent
{
    use TablesModelsTrait;


    /* Переменные настройки */

    public static $CalcLogs;
    protected $tmpDirPath = 'mirTmpfiles/tmpLoadedFiles/';
    protected $tmpTableChangesDirPath = 'mirTmpfiles/tmpTableChangesDirPath/';
    protected $logsDir = 'myLogs/';

    public static $MaxFileSizeMb = 10;
    public static $timeLimit = 30;

    protected $execSSHOn = false;

    const LANG = "";

    /* Переменные работы конфига */
    protected static $handlersRegistered = false;
    /**
     * @var Log
     */
    protected static $logPhp;

    protected $CalculateExtensions;

    /**
     * @var string production|development
     */
    protected $env;
    public const ENV_LEVELS = ["production" => "production", "development" => "development"];


    protected $dbConnectData;

    private $settingsCache;
    protected $hostName;
    /**
     * @var string
     */
    protected $schemaName;


    /**
     * microtime of start script (this config part)
     *
     * @var float
     */
    protected $mktimeStart;
    /**
     * @var string
     */
    protected $baseDir;
    protected $procVars = [];


    public function __construct($env = self::ENV_LEVELS["production"])
    {
        $this->mktimeStart = microtime(true);
        set_time_limit(static::$timeLimit);
        $this->logLevels =
            $env === self::ENV_LEVELS["production"] ? ['critical', 'emergency']
                : ['error', 'debug', 'alert', 'critical', 'emergency', 'info', 'notice', 'warning'];

        $this->baseDir = $this->getBaseDir();
        $this->tmpDirPath = $this->baseDir . $this->tmpDirPath;
        $this->tmpTableChangesDirPath = $this->baseDir . $this->tmpTableChangesDirPath;
        $this->logsDir = $this->baseDir . $this->logsDir;
        $this->env = $env;
    }

    public function getDefaultSender()
    {
        return "no-reply@" . $this->getFullHostName();
    }

    public function setSessionCookieParams()
    {
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    public function getBaseDir()
    {
        return dirname((new \ReflectionClass(get_called_class()))->getFileName()) . DIRECTORY_SEPARATOR;
    }

    public function setLogIniAndHandlers()
    {
        $this->setLogIni();
        $this->registerHandlers();
    }

    public function getClearConf()
    {
        return new static($this->env);
    }

    public function cronErrorActions($cronRow, $User, $exception)
    {
        try {
            $Mir = new Mir($this, $User);
            $Table = $Mir->getTable('settings');
            $Cacl = new CalculateAction('=: insert(table: "notifications"; field: \'user_id\'=1; field: \'active\'=true; field: \'title\'="Ошибка крона"; field: \'code\'="admin_text"; field: "vars"=$#vars)');
            $Cacl->execAction(
                'kod',
                $cronRow,
                $cronRow,
                $Table->getTbl(),
                $Table->getTbl(),
                $Table,
                'exec',
                ['vars' => ['text' => 'Ошибка крона <b>' . ($cronRow['descr'] ?? $cronRow['id']) . '</b>:<br>' . $exception->getMessage()]]
            );
        } catch (\Exception $e) {
        }

        $this->sendMail(
            static::adminEmail,
            'Ошибка крона ' . $this->getSchema() . ' ' . ($cronRow['descr'] ?? $cronRow['id']),
            $exception->getMessage()
        );
    }

    public function getTemplatesDir()
    {
        return dirname(__FILE__) . '/../../templates';
    }

    public function getTmpDir()
    {
        if (!is_dir($this->tmpDirPath)) {
            mkdir($this->tmpDirPath, 0777, true);
        }
        return $this->tmpDirPath;
    }

    public function getTmpTableChangesDir()
    {
        if (!is_dir($this->tmpTableChangesDirPath)) {
            mkdir($this->tmpTableChangesDirPath, 0777, true);
        }
        return $this->tmpTableChangesDirPath;
    }

    public function getSchema($force = true)
    {
        if ($force && empty($this->schemaName)) {
            errorException::criticalException('Схема не подключена', $this);
        }
        return $this->schemaName;
    }

    public function getFullHostName()
    {
        return $this->hostName;
    }

    public function getFilesDir()
    {
        return $this->baseDir . 'http/fls/';
    }


    public function getCryptSolt()
    {
        return $this->getSettings('crypt_solt');
    }

    /**
     * @return bool
     */
    public function isExecSSHOn(): bool
    {
        return $this->execSSHOn;
    }


    /********************* MAIL SECTION **************/

    protected function mailBodyAttachments($body, $attachmentsIn = [])
    {
        $attachments = [];
        foreach ($attachmentsIn as $k => $v) {
            if (!preg_match('/.+\.[a-zA-Z]{2,5}$/', $k)) {
                $attachments[preg_replace('`.*?/([^/]+\.[^/]+)$`', '$1', $v)] = $v;
            } else {
                $attachments[$k] = $v;
            }
            $attachments[$k] = File::getFilePath($v, $this);
        }

        $body = preg_replace_callback(
            '~src\s*=\s*([\'"]?)(?:http(?:s?)://' . $this->getFullHostName() . ')?/fls/(.*?)\1~',
            function ($matches) use (&$attachments) {
                if (!empty($matches[2]) && $file = File::getFilePath($matches[2], $this)) {
                    $md5 = md5($matches[2]) . '.' . preg_replace('/.*\.([a-zA-Z]{2,5})$/', '$1', $matches[2]);
                    $attachments[$md5] = $file;
                    return 'src="cid:' . $md5 . '"';
                }
            },
            $body
        );

        return [$body, $attachments];
    }

    /**
     * Override this function by traits or directly for send emails from AuthController or Mir-code
     *
     *
     * @param $to
     * @param $title
     * @param $body
     * @param array $attachments
     * @param null $from
     * @throws errorException
     */
    public function sendMail($to, $title, $body, $attachments = [], $from = null)
    {
        throw new errorException('Настройки для отправки почты не заданы');
    }

    /********************* ANONYM SECTION **************/

    protected const ANONYM_ALIAS = "An";

    public function getAnonymHost()
    {
        return $this->getFullHostName();
    }

    /**
     * TODO connect method to index.php
     *
     * @return string
     */
    public function getAnonymModul()
    {
        return static::ANONYM_ALIAS;
    }

    /********************* HANDLERS SECTION **************/

    protected function registerHandlers()
    {
        if (!static::$handlersRegistered) {
            register_shutdown_function([$this, 'shutdownHandler']);

            /*Для записи нотификаций от php в лог*/
            static::$logPhp = $this->getLogger('php');
            set_error_handler([$this, 'errorHandler']);

            static::$handlersRegistered = true;
        }
    }

    public function errorHandler($errno, $errstr, $errfile, $errline)
    {
        $this->getLogger('php')->error($errfile . ':' . $errline . ' ' . $errstr);
    }

    public function shutdownHandler()
    {
        $error = error_get_last();

        if ($error !== null) {
            $errno = $error["type"];
            $errfile = $error["file"];
            $errline = $error["line"];
            $errstr = $error["message"];


            if ($errno === E_ERROR) {
                $errorStr = $errstr;
                if (empty($_POST['ajax'])) {
                    echo $errorStr;
                }

                static::errorHandler($errno, $errorStr, $errfile, $errline);
                if (static::$logPhp) {
                    static::$logPhp->error($errfile . ':' . $errline . ' ' . $errstr);
                }
                if (static::$CalcLogs) {
                    $this->getLogger('sql')->error(static::$CalcLogs);
                }
            } else {
                $errorStr = $errstr;
            }

            if (!empty($_POST['ajax'])) {
                echo json_encode(['error' => $errorStr], JSON_UNESCAPED_UNICODE);
            }
        }
    }


    /**
     * @param $uri
     * @return array|string[]
     */
    public function getActivationData($uri)
    {
        $split = explode('/', substr($uri, 1), 2);
        if (!preg_match('/^[a-z0-9_]+$/i', $split[0])) {
            $split[0] = '';
            $split[1] = $uri;
        }
        if ($split[0] === $this->getAnonymModul()) {
            $split[0] = "An";
        } elseif ($split[0] === 'An') {
            die('Ошибка доступа к модулю анонимных таблиц');
        }

        return [$split[0], $split[1] ?? ''];
    }


    /********************* LOGGERS SECTION **************/

    /**
     * @var array
     */
    protected $Loggers = [];
    /**
     * @var string[]
     */
    protected $logLevels;

    /**
     * @param string $type
     * @param null|array $levels
     * @param null $templateCallback
     * @param null $fileName
     * @return Log
     */
    public function getLogger(string $type, $levels = null, $templateCallback = null, $fileName = null)
    {
        if (key_exists($type, $this->Loggers)) {
            return $this->Loggers[$type];
        }

        if (!$levels) {
            $levels = $this->logLevels;
        }

        $dir = $this->logsDir;
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        $this->Loggers[$type] = new Log(
            $fileName ?? $dir . $type . '_' . $this->getSchema(false) . '.log',
            $levels,
            $templateCallback
        );

        return $this->Loggers[$type];
    }


    public function getCalculateExtensionFunction($funcName)
    {
        $this->getObjectWithExtFunctions();
        if (!property_exists(
            $this->CalculateExtensions,
            $funcName
        ) || !is_callable($this->CalculateExtensions->$funcName)) {
            throw new errorException('Функция [[' . $funcName . ']] не найдена');
        }
        return $this->CalculateExtensions->$funcName;
    }

    public function getExtFunctionsTemplates()
    {
        $this->getObjectWithExtFunctions();
        return $this->CalculateExtensions->jsTemplates ?? '[]';
    }

    public function getObjectWithExtFunctions()
    {
        if (!$this->CalculateExtensions) {
            if (file_exists($fName = dirname((new \ReflectionClass($this))->getFileName()) . '/CalculateExtensions.php')) {
                include($fName);
            }
            $this->CalculateExtensions = $CalculateExtensions ?? new \stdClass();
        }
        return $this->CalculateExtensions;
    }

    public function getLang()
    {
        return static::LANG;
    }

    protected function setLogIni()
    {
        ini_set('log_errors', 1);
        switch ($this->env) {
            case 'production':
                ini_set('display_errors', 0);
                ini_set('error_reporting', E_ALL & ~E_DEPRECATED & ~E_STRICT);
                break;
            default:
                ini_set('display_errors', 1);
                ini_set('error_reporting', E_ALL);
        }
    }

    /********************* DATABASE SECTION **************/

    abstract public static function getSchemas();

    public function getSshPostgreConnect($type)
    {
        $db = $this->getDb(false);
        if (empty($db[$type])) {
            errorException::criticalException('Не задан путь к ssh скрипту ' . $type, $this);
        }
        $pathPsql = $db[$type];
        $dbConnect = sprintf(
            "postgresql://%s:%s@%s/%s",
            $db['username'],
            $db['password'],
            $db['host'],
            $db['dbname']
        );

        return "$pathPsql --dbname=\"$dbConnect\"";
    }

    public function getDb($withSchema = true)
    {
        $db = $this->dbConnectData ?? static::db;
        if ($withSchema) {
            $db['schema'] = $db['schema'] ?? $this->getSchema();
        }
        return $db;
    }

    /**
     * @var Sql
     */
    protected $Sql;

    public function getSql($mainInstance = true, $withSchema = true, $Logger = null)
    {
        $getSql = function () use ($withSchema, $Logger) {
            return new Sql($this->getDb($withSchema), $Logger ?? $this->getLogger('sql'), $withSchema);
        };
        if ($mainInstance) {
            return $this->Sql ?? $this->Sql = $getSql();
        } else {
            return $getSql();
        }
    }


    /**
     * Load and Cache settings from table "settings"
     *
     * @param null $name
     * @return array
     */
    public function getSettings($name = null)
    {
        if (!$this->settingsCache) {
            $settings = json_decode(
                $this->getTableRow('settings')['header'],
                true
            );
            $this->settingsCache = [];
            foreach ($settings as $s_key => $s_value) {
                if (is_array($s_value) && key_exists('v', $s_value)) {
                    $this->settingsCache[$s_key] = $s_value['v'];
                } else {
                    $this->settingsCache[$s_key] = $s_value;
                }
            }
            if (empty($this->settingsCache['mir_name'])) {
                $this->settingsCache['mir_name'] = $this->getSchema();
            }
        }


        if ($name) {
            return $this->settingsCache[$name] ?? null;
        }
        return $this->settingsCache;
    }

    public function globVar($name, $params = [])
    {
        static $sql = null;
        static $prepareInsertOrUpdate = null;
        static $prepareSelect = null;
        static $prepareSelectDefault = null;
        static $prepareSelectBlockFalse = null;

        if (empty($sql)) {
            $sql = $this->getSql(false);
        }

        $checkError = function ($prep) use ($params, $name, $sql) {
            if ($prep->errorCode() === '42P01') {
                $sql->exec(
                    <<<SQL
create table "_globvars"
(
    name     text                                                      not null,
    value     jsonb,
    blocked     timestamp,
    dt        timestamp default ('now'::text)::timestamp without time zone not null
)
SQL
                );
                $sql->exec('create UNIQUE INDEX _globvars_name_index on _globvars (name)');
                return $this->globVar($name, $params);
            } else {
                throw new SqlException($prep->errorInfo()[1]);
            }
        };
        $getPrepareSelect = function () use (&$prepareSelect, $sql) {
            if (!$prepareSelect) {
                $prepareSelect = $sql->getPrepared('select value, dt from _globvars where name = ?');
            }
            return $prepareSelect;
        };
        $getPrepareSelectBlocked = function ($interval) use ($sql): \PDOStatement {
            return $sql->getPrepared('WITH time AS(
    select now() + interval \'' . $interval . '\' as times
)
UPDATE _globvars SET blocked=
    CASE
        WHEN (blocked is null OR blocked<=now()) THEN (SELECT times FROM time)
        ELSE blocked
        END
WHERE name = :name
RETURNING value, dt, blocked, blocked=(SELECT times FROM time) as was_blocked');
        };
        $getPrepareSelectDefault = function () use (&$prepareSelectDefault, $sql) {
            if (!$prepareSelectDefault) {
                $prepareSelectDefault = $sql->getPrepared('INSERT INTO _globvars (name, value) 
VALUES (?,?)
ON CONFLICT (name) DO UPDATE 
  SET name = excluded.name RETURNING value, dt');
            }
            return $prepareSelectDefault;
        };
        $getPrepareSelectBlockedFalse = function () use (&$prepareSelectBlockFalse, $sql) {
            if (!$prepareSelectBlockFalse) {
                $prepareSelectBlockFalse = $sql->getPrepared('INSERT INTO _globvars (name) 
VALUES (?)
ON CONFLICT (name) DO UPDATE 
  SET blocked = NULL RETURNING value, dt');
            }
            return $prepareSelectBlockFalse;
        };
        $getPrepareInsertOrUpdate = function () use ($sql, &$prepareInsertOrUpdate) {
            if (!$prepareInsertOrUpdate) {
                $prepareInsertOrUpdate = $sql->getPrepared('INSERT INTO _globvars (name, value) 
VALUES (?,?)
ON CONFLICT (name) DO UPDATE 
  SET value = excluded.value, 
      blocked = null,
      dt = (\'now\'::text)::timestamp without time zone RETURNING value, dt');
            }
            return $prepareInsertOrUpdate;
        };


        $returnData = function ($prepare) use ($checkError) {
            if ($prepare->errorCode() !== '00000') {
                return $checkError($prepare);
            }
            if ($data = $prepare->fetch()) {
                if ($params['date'] ?? false) {
                    return ['date' => $data["dt"], 'value' => json_decode($data["value"], true)["v"]];
                } else {
                    return json_decode($data["value"], true)["v"];
                }
            } else {
                return null;
            }
        };

        if (key_exists('value', $params)) {
            $getPrepareInsertOrUpdate()->execute([$name, json_encode(
                ['v' => $params['value']],
                JSON_UNESCAPED_UNICODE
            )]);
            return $returnData($prepareInsertOrUpdate);
        } elseif (key_exists('default', $params)) {
            $getPrepareSelectDefault()->execute([$name, json_encode(
                ['v' => $params['default']],
                JSON_UNESCAPED_UNICODE
            )]);
            return $returnData($prepareSelectDefault);

        } elseif (key_exists('block', $params)) {
            if (!$params['block']) {
                $getPrepareSelectBlockedFalse()->execute([$name]);
                return $returnData($prepareSelectBlockFalse);
            } else {
                while (true) {
                    $prepareSelectBlocked = $getPrepareSelectBlocked((float)$params['block'] . ' second');
                    $prepareSelectBlocked->execute(['name' => $name]);

                    if ($prepareSelectBlocked->errorCode() !== '00000') {
                        return $checkError($prepareSelectBlocked);
                    }

                    if ($data = $prepareSelectBlocked->fetch()) {
                        if ($data['was_blocked']) {
                            if ($params['date'] ?? false) {
                                return ['date' => $data["dt"], 'value' => json_decode($data["value"], true)["v"]];
                            } else {
                                return json_decode($data["value"], true)["v"];
                            }
                        }
                    } else {
                        return null;
                    }
                }
            }
        } else {
            $getPrepareSelect()->execute([$name]);

            return $returnData($prepareSelect);
        }
    }

    public function procVar($name = null, $params = [])
    {
        if (empty($name)) {
            return array_keys($this->procVars ?? []);
        }

        if (key_exists('value', $params)) {
            $this->procVars[$name] = $params['value'];
        } elseif (key_exists('default', $params)) {
            if (!key_exists($name, $this->procVars)) {
                $this->procVars[$name] = $params['default'];
            }
        }

        return $this->procVars[$name] ?? null;
    }

    public function getMirFooter()
    {
        $genTime = round(microtime(true) - $this->mktimeStart, 4);
        $mb = memory_get_peak_usage(true) / 1024 / 1024;
        if ($mb < 1) {
            $mb = '< 1 ';
        } else {
            $mb = round($mb, 2);
        }
        $memory_limit = ini_get('memory_limit');
        $SchemaName = $this->getSchema();
        $version = Mir::VERSION;

        return <<<FOOTER
    Время обработки страницы: $genTime сек.<br/>
    Оперативная память: {$mb}M. из $memory_limit.<br/>
    Sql схема: $SchemaName, V $version<br/>
FOOTER;
    }
}
