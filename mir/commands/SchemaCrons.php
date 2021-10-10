<?php


namespace mir\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use mir\common\configs\MultiTrait;
use mir\config\Conf;

class SchemaCrons extends Command
{
    protected function configure()
    {
        $this->setName('schema-crons')
            ->setDescription('Execute mir codes of table crons for single install')
            ->addArgument('datetime', InputOption::VALUE_REQUIRED, 'Enter datetime');

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

        if ($date = $input->getArgument('datetime')) {
            $date = date_create($date);
        } else {
            $date = date_create();
        }
        if (!$date) {
            throw new \Exception('Date format is not correct');
        }

        $nowMinute = $date->format('i');
        $nowHour = $date->format('H');
        $nowDay = $date->format('d');
        $nowMonth = $date->format('m');
        $nowWeekDay = $date->format('N');

        $checkRules = [
            'minute' => $nowMinute
            , 'hour' => $nowHour
            , 'day_of_month' => $nowDay
            , 'month' => $nowMonth
            , 'weekday' => $nowWeekDay
        ];
        $crons = $Conf->getModel('crons')->getAll(
            ['status' => "true"],
            'id,' . implode(',', array_keys($checkRules))
        );
        foreach ($crons as $rule) {
            foreach ($checkRules as $field => $val) {
                $checkField = json_decode($rule[$field], true);
                if (!empty($checkField) && !in_array($val, $checkField)) {
                    continue 2;
                }
            }
            $schemaName = "";

            if (is_callable([$Conf, 'setHostSchema'])) {
                $schemaName=$Conf->getSchema();
            }

            $id = $rule['id'];

            `{$_SERVER['SCRIPT_FILENAME']} schema-cron $id $schemaName > /dev/null 2>&1 &`;
        }
    }
}
