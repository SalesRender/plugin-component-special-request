<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 16.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Commands;

use Khill\Duration\Duration;
use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models\FailedRequestLog;
use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestDispatcher;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XAKEPEHOK\Path\Path;

class SpecialRequestDispatcherCommand extends Command
{

    const MAX_MEMORY = 25 * 1024 * 1024;

    private int $started;

    private int $handed = 0;

    public function __construct()
    {
        parent::__construct("specialRequestDispatcher");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->started = time();

        $mutex = fopen((string) Path::root()->down('runtime')->down('specialRequestDispatcher.mutex'), 'c');
        if (!flock($mutex, LOCK_EX|LOCK_NB)) {
            fclose($mutex);
            throw new RuntimeException('Dispatcher already running');
        }

        $this->writeUsedMemory($output);

        $lastTime = time();
        do {

            if ((time() - 5 ) > $lastTime) {
                $this->writeUsedMemory($output);
                $lastTime = time();
            }

            /** @var SpecialRequestDispatcher[] $requests */
            $requests = SpecialRequestDispatcher::findByCondition([
                'OR' => [
                    'attemptAt' => null,
                    'attemptAt[<]' => time() - 60,
                ],
                "ORDER" => ["attemptAt" => "ASC"],
                'LIMIT' => 50
            ]);

            foreach ($requests as $request) {
                $this->handed++;
                if ($request->send()) {
                    $output->writeln("<fg=green>[{$request->getRequest()->getMethod()}}]</> {$request->getRequest()->getUri()}.");
                } else {
                    $output->writeln("<fg=red>[{$request->getRequest()->getMethod()}}]</> {$request->getRequest()->getUri()}.");
                }
            }

            SpecialRequestDispatcher::freeUpMemory();
            FailedRequestLog::freeUpMemory();

            sleep(1);

        } while (memory_get_usage(true) < self::MAX_MEMORY);

        $output->writeln('<info> -- High memory usage. Stopped -- </info>');

        flock($mutex, LOCK_UN);
        fclose($mutex);

        return 0;
    }

    private function writeUsedMemory(OutputInterface $output)
    {
        $used = round(memory_get_usage(true) / 1024 / 1024, 2);
        $max = round(self::MAX_MEMORY / 1024 / 1024, 2);
        $uptime = (new Duration(max(time() - $this->started, 1)))->humanize();
        $output->writeln("<info> -- Handed: {$this->handed}; Used {$used} MB of {$max} MB; Uptime: {$uptime} -- </info>");
    }

}