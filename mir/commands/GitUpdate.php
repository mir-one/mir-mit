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

class GitUpdate extends Command
{
    protected function configure()
    {
        $this->setName('git-update')
            ->addOption('force', '', InputOption::VALUE_NONE, 'Force update without checking changing major version.')
            ->setDescription('update from git origin master && composer && schema(s)-update');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->getOption('force')) {
            $error = true;
            if ($mirClass = file_get_contents('https://raw.githubusercontent.com/mir-one/mir-mit/master/mir/common/Mir.php')) {
                if (preg_match('/public\s*const\s*VERSION = \'(\d+)/', $mirClass, $matches)) {
                    if ((string)preg_replace('/^(\d+).*$/', '$1', Mir::VERSION) !== $matches[1]) {
                        die('Смена мажорной версии. ' .
                            'Проверьте параметры сервера и нарушения обратной совместимости. ' .
                            'Используйте --force, если уверены в обновлении.');
                    } else {
                        $error = false;
                    }
                }
            }
            if ($error) {
                die('Доступ к файлам на github был ограничен. Проверка смены мажорной версии не пройдена. ' .
                    'Проверьте самостоятельно на https://github.com/mir-one/mir-mit/blob/master/mir/common/Mir.php ' .
                    'и используйте --force для исключения этой проверки.');
            }
        }

        $Conf = new Conf();

        if (is_callable([$Conf, 'setHostSchema'])) {
            passthru('git pull origin master && php -f composer.phar install --no-dev && bin/mir schemas-update');
        } else {
            passthru('git pull origin master && php -f composer.phar install --no-dev && bin/mir schema-update');
        }

        return 0;
    }
}
