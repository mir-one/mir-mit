<?php


namespace mir\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use mir\common\Auth;
use mir\common\configs\MultiTrait;
use mir\common\errorException;
use mir\common\Mir;
use mir\config\Conf;

class SchemaPasswd extends Command
{
    protected function configure()
    {
        $this->setName('schema-passwd')
            ->setDescription('Change password for schema user')
            ->addArgument('login', InputOption::VALUE_REQUIRED, 'Enter user login')
            ->addArgument('password', InputOption::VALUE_REQUIRED, 'Enter user new password');
        if (key_exists(MultiTrait::class, class_uses(Conf::class, false))) {
            $this->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'Enter schema name');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!class_exists(Conf::class)) {
            $output->writeln('ERROR: config class not found');
        }
        $Conf = new Conf();

        if (is_callable([$Conf, 'setHostSchema'])) {
            if ($schema = $input->getOption('schema')) {
                $Conf->setHostSchema(null, $schema);
            }
        }


        if (empty($login = $input->getArgument('login'))) {
            throw new errorException('Enter user login');
        }
        if (empty($pass = $input->getArgument('password'))) {
            throw new errorException('Enter new password');
        }

        $Mir = new Mir($Conf, Auth::loadAuthUserByLogin($Conf, 'service', false));
        if ($user = $Mir->getModel('users')->get(['login' => $login])) {
            $Mir->transactionStart();
            $Mir->getTable('users')->reCalculateFromOvers(['modify' => [$user['id'] => ['pass' => $pass]]]);
            $Mir->transactionCommit();
        } else {
            throw new errorException('User with login ' . $login . ' not found');
        }

        return 0;
    }
}
