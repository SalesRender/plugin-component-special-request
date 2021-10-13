<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 16.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Commands;

use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestDispatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SpecialRequestDispatcherCommand extends Command
{

    public function __construct()
    {
        parent::__construct("specialRequest:dispatch");
    }

    protected function configure()
    {
        $this
            ->setDescription('Run special request operation in background')
            ->addArgument('id', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $request = SpecialRequestDispatcher::findById($input->getArgument('id'));
        if (is_null($request)) {
            return Command::INVALID;
        }

        if ($request->send()) {
            $output->writeln("<fg=green>[{$request->getRequest()->getMethod()}}]</> {$request->getRequest()->getUri()}.");
            return Command::SUCCESS;
        }

        $output->writeln("<fg=red>[{$request->getRequest()->getMethod()}}]</> {$request->getRequest()->getUri()}.");
        return Command::FAILURE;
    }

}