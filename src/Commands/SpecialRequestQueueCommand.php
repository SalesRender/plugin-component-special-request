<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 13.10.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Commands;

use Leadvertex\Plugin\Components\Db\ModelInterface;
use Leadvertex\Plugin\Components\Queue\Commands\QueueCommand;
use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;
use Medoo\Medoo;
use Symfony\Component\Console\Output\OutputInterface;

class SpecialRequestQueueCommand extends QueueCommand
{

    public function __construct(int $maxMemoryInMb = 25)
    {
        parent::__construct("specialRequest", $_ENV['LV_PLUGIN_SR_QUEUE_LIMIT'] ?? 100, $maxMemoryInMb);
    }

    protected function findModels(): array
    {
        SpecialRequestTask::freeUpMemory();
        return SpecialRequestTask::findByCondition([
            'OR' => [
                'attemptAt' => null,
                'attemptAt[<=]' => Medoo::raw('(:time - <attemptTimeout>)', [':time' => time()]),
            ],
            'id[!]' => array_keys($this->processes),
            "ORDER" => ["attemptAt" => "ASC"],
            'LIMIT' => $this->limit
        ]);
    }

    /**
     * @param SpecialRequestTask|ModelInterface $model
     * @param OutputInterface $output
     */
    protected function startedLog(ModelInterface $model, OutputInterface $output): void
    {
        $verb = $model->getRequest()->getMethod();
        $uri = $model->getRequest()->getUri();
        $output->writeln("<info>[STARTED]</info>Request '{$model->getId()}' {$verb} {$uri}");
    }

}