<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 13.10.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Commands;

use Khill\Duration\Duration;
use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestDispatcher;
use Medoo\Medoo;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use XAKEPEHOK\Path\Path;

class SpecialRequestQueueCommand extends Command
{

    private int $started;
    private int $limit;
    private int $handed = 0;
    private int $maxMemoryInMb;
    /** @var Process[] */
    private array $processes = [];

    public function __construct(int $maxMemoryInMb = 25)
    {
        parent::__construct("specialRequest:queue");
        $this->maxMemoryInMb = $maxMemoryInMb * 1024 * 1024;
        $this->limit = $_ENV['LV_PLUGIN_SR_QUEUE_LIMIT'] ?? 100;
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

            foreach ($this->processes as $key => $process) {
                if (!$process->isTerminated()) {
                    continue;
                }

                if ($process->isSuccessful()) {
                    $output->writeln("<fg=green>[FINISHED]</> Request '{$key}' was finished.");
                } else {
                    $output->writeln("<fg=red>[FAILED]</> Request '{$key}' with code '{$process->getExitCode()}' and message '{$process->getExitCodeText()}'.");
                }

                unset($this->processes[$key]);
            }

            /** @var SpecialRequestDispatcher[] $requests */
            $requests = SpecialRequestDispatcher::findByCondition([
                'OR' => [
                    'attemptAt' => null,
                    'attemptAt[<=]' => Medoo::raw('(:time - <attemptTimeout>)', [':time' => time()]),
                ],
                'id[!]' => array_keys($this->processes),
                "ORDER" => ["attemptAt" => "ASC"],
                'LIMIT' => $this->limit
            ]);

            foreach ($requests as $request) {
                if ($this->handleQueue($request)) {
                    $verb = $request->getRequest()->getMethod();
                    $uri = $request->getRequest()->getUri();
                    $output->writeln("<info>[STARTED]</info>Request '{$request->getId()}' {$verb} {$uri}");
                }
            }

            sleep(1);

        } while (memory_get_usage(true) < $this->maxMemoryInMb);

        $output->writeln('<info> -- High memory usage. Stopped -- </info>');

        flock($mutex, LOCK_UN);
        fclose($mutex);

        return 0;
    }

    private function handleQueue(SpecialRequestDispatcher $dispatcher): bool
    {
        $this->processes = array_filter($this->processes, function (Process $process) {
            return $process->isRunning();
        });

        if ($this->limit > 0 && count($this->processes) >= $this->limit) {
            return false;
        }

        if (isset($this->processes[$dispatcher->getId()])) {
            return false;
        }

        $this->processes[$dispatcher->getId()] = new Process([
            $_ENV['LV_PLUGIN_PHP_BINARY'],
            (string) Path::root()->down('console.php'),
            'specialRequest:dispatch',
            $dispatcher->getId(),
        ]);

        $this->processes[$dispatcher->getId()]->start();

        $this->handed++;

        return true;
    }

    private function writeUsedMemory(OutputInterface $output)
    {
        $used = round(memory_get_usage(true) / 1024 / 1024, 2);
        $max = round($this->maxMemoryInMb / 1024 / 1024, 2);
        $uptime = (new Duration(max(time() - $this->started, 1)))->humanize();
        $output->writeln("<info> -- Handed: {$this->handed}; Used {$used} MB of {$max} MB; Uptime: {$uptime} -- </info>");
    }

}