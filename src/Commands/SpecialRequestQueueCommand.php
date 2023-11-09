<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 13.10.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace SalesRender\Plugin\Components\SpecialRequestDispatcher\Commands;

use SalesRender\Plugin\Components\Db\ModelInterface;
use SalesRender\Plugin\Components\Queue\Commands\QueueCommand;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;
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
        $condition = [
            'OR' => [
                'attemptLastTime' => null,
                'attemptLastTime[<=]' => Medoo::raw('(:time - <attemptInterval>)', [':time' => time()]),
            ],
            "ORDER" => ["createdAt" => "ASC"],
            'LIMIT' => $this->limit
        ];

        $processes = array_keys($this->processes);
        if (!empty($processes)) {
            $condition['id[!]'] = $processes;
        }

        return SpecialRequestTask::findByCondition($condition);
    }

    /**
     * @param SpecialRequestTask|ModelInterface $model
     * @param OutputInterface $output
     */
    protected function startedLog(ModelInterface $model, OutputInterface $output): void
    {
        $verb = $model->getRequest()->getMethod();
        $uri = $model->getRequest()->getUri();
        $output->writeln("<info>[STARTED]</info> Request '{$model->getId()}' {$verb} {$uri}");
    }

}