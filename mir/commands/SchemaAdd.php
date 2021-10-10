<?php


namespace mir\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use mir\common\errorException;
use mir\common\MirInstall;
use mir\common\User;
use mir\config\Conf;
use mir\config\Conf2;

class SchemaAdd extends Command
{
    protected function configure()
    {
        $this->setName('schema-add')
            ->setDescription('Add new schema')
            ->addArgument('name', InputArgument::REQUIRED, 'Enter schema name')
            ->addArgument('host', InputArgument::REQUIRED, 'Enter schema host')
            ->addArgument('user_login', InputOption::VALUE_REQUIRED, 'Enter mir admin login', 'admin')
            ->addArgument('user_pass', InputOption::VALUE_REQUIRED, 'Enter mir admin password', '1111');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!class_exists(Conf::class)) {
            $output->writeln('ERROR: config class not found');
        }
        $Conf=new Conf('dev');

        if (!$input->getArgument('name')) {
            throw new errorException('Enter schema name');
        }
        if (!$input->getArgument('host')) {
            throw new errorException('Enter schema host');
        }

        $Conf->setHostSchema($input->getArgument('host'), $input->getArgument('name'));

        $MirInstall=new MirInstall($Conf, new User(['login' => 'service', 'roles' => ["1"], 'id' => 1], $Conf), $output);

        $confs=[];
        $confs['schema_exists'] = false;
        $confs['user_login'] = $input->getArgument('user_login');
        $confs['user_pass'] = $input->getArgument('user_pass');


        $MirInstall->createSchema($confs, function ($file) {
            return dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'moduls' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . $file;
        });



        $output->writeln('save Conf.php');

        $ConfFile= (new \ReflectionClass(Conf::class))->getFileName();
        $ConfFileContent=file_get_contents($ConfFile);

        if (!preg_match('~\/\*\*\*getSchemas\*\*\*\/[^$]*{[^$]*return([^$]*)\}[^$]*/\*\*\*getSchemasEnd\*\*\*/~', $ConfFileContent, $matches)) {
            throw new \Exception('Format of file not correct. Can\'t replace function getSchemas');
        }
        eval("\$schemas={$matches[1]}");
        $schemas[$input->getArgument('host')]=$input->getArgument('name');
        $ConfFileContent= preg_replace('~(\/\*\*\*getSchemas\*\*\*\/[^$]*{[^$]*return\s*)([^$]*)(\}[^$]*/\*\*\*getSchemasEnd\*\*\*/)~', '$1'.var_export($schemas, 1).';$3', $ConfFileContent);
        copy($ConfFile, $ConfFile.'_old');
        file_put_contents($ConfFile, $ConfFileContent);
    }
}
