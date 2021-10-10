<?php


namespace mir\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use mir\config\Conf;

class SchemasCrons extends Command
{
    protected function configure()
    {
        $this->setName('schemas-crons')
            ->setDescription('Execute crons of schemas for muti-install');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        foreach (array_unique(array_values(Conf::getSchemas())) as $schemaName) {
            `{$_SERVER['SCRIPT_FILENAME']} schema-crons "" $schemaName > /dev/null 2>&1 &`;
        }
    }
}
