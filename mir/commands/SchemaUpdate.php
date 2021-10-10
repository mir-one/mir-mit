<?php


namespace mir\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use mir\common\configs\MultiTrait;
use mir\common\errorException;
use mir\common\MirInstall;
use mir\common\User;
use mir\config\Conf;
use mir\config\Conf2;

class SchemaUpdate extends Command
{
    protected function configure()
    {
        $this->setName('schema-update')
            ->setDescription('Update schema')
            ->addArgument(
                'matches',
                InputArgument::OPTIONAL,
                'Enter source name',
                'mir_' . (new Conf())->getLang()
            )
            ->addArgument('file', InputArgument::OPTIONAL, 'Enter schema update filepath', 'sys_update');

        if (key_exists(MultiTrait::class, class_uses(Conf::class, false))) {
            $this->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Enter schema name', '');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!class_exists(Conf::class)) {
            $output->writeln('ERROR: config class not found');
        }
        $Conf = new Conf('dev');
        if (is_callable([$Conf, 'setHostSchema'])) {
            if ($schema = $input->getOption('schema')) {
                $Conf->setHostSchema(null, $schema);
            }
        }
        $sourceName = $input->getArgument('matches');

        $file = $input->getArgument('file');

        if ($file === 'sys_update') {
            $file = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'moduls' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'start_' . $Conf->getLang() . '.json.gz.ttm';
        }

        $MirInstall = new MirInstall(
            $Conf,
            new User(['login' => 'service', 'roles' => ["1"], 'id' => 1], $Conf),
            $output
        );

        if (!is_file($file)) {
            throw new errorException('File not found');
        }
        if (!($cont = file_get_contents($file))) {
            throw new errorException('File is empty');
        }
        if (!($cont = gzdecode($cont))) {
            throw new errorException('File is not gzip');
        }
        if (!($cont = json_decode($cont, true))) {
            throw new errorException('File is not json');
        }

        if (($matches = json_decode($sourceName, true)) && is_array($matches) && key_exists(
            'name',
            $matches
        ) && key_exists('matches', $matches)) {
            $sourceName=$matches['name'];
            $matches=$matches['matches'];
        } else {
            $matches = $MirInstall->getMir()->getTable('ttm__updates')->getTbl()['params']['h_matches']['v'][$sourceName] ?? [];
        }
        $cont = MirInstall::applyMatches($cont, $matches);

        $MirInstall->updateSchema($cont, true, $sourceName);
    }
}
