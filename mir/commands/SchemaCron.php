<?php


namespace mir\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use mir\common\Auth;
use mir\common\calculates\CalculateAction;
use mir\common\configs\MultiTrait;
use mir\common\errorException;
use mir\common\Model;
use mir\common\tableSaveException;
use mir\common\Mir;
use mir\config\Conf;

class SchemaCron extends Command
{
    protected function configure()
    {
        $this->setName('schema-cron')
            ->setDescription('Execute exact mir code of table crons')
            ->addArgument('cronId', InputOption::VALUE_REQUIRED, 'Enter cron id');
        if (key_exists(MultiTrait::class, class_uses(Conf::class, false))) {
            $this->addArgument('schema', InputOption::VALUE_REQUIRED, 'Enter schema name');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $Conf = new Conf();

        if (is_callable([$Conf, 'setHostSchema'])) {
            if ($schema = $input->getArgument('schema')) {
                $Conf->setHostSchema(null, $schema);
            }
        }


        if ($cronId = $input->getArgument('cronId')) {
            if ($cronRow = $Conf->getModel('crons')->get(['id' => (int)$cronId, 'status' => 'true'])) {
                $cronRow = Model::getClearValuesWithExtract($cronRow);
            }else{
                throw new \Exception('Row cron not found or not active');
            }
        }else{
            throw new \Exception('Id of cron not found or empty');
        }




        $User = Auth::loadAuthUserByLogin($Conf, 'cron', false);
        $i = 0;
        while (++$i <= 4) {
            try {
                try {
                    $Mir = new Mir($Conf, $User);
                    $Mir->transactionStart();
                    $Table = $Mir->getTable('crons');
                    $Calc = new CalculateAction($cronRow['code']);
                    $Calc->execAction('CRON',
                        $cronRow,
                        $cronRow,
                        $Table->getTbl(),
                        $Table->getTbl(),
                        $Table,
                        'exec',
                        []);
                    $Mir->transactionCommit();
                } catch (errorException $e) {
                    $Conf = $Conf->getClearConf();
                    $Conf->cronErrorActions($cronRow, $User, $e);
                }
                break;
            } catch (tableSaveException $exception) {
                $Conf = $Conf->getClearConf();
            }
        }
    }
}
