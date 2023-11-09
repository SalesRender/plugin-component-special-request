<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 16.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace SalesRender\Plugin\Components\SpecialRequestDispatcher\Commands;

use GuzzleHttp\Exception\BadResponseException;
use SalesRender\Plugin\Components\Guzzle\Guzzle;
use SalesRender\Plugin\Components\Queue\Commands\QueueHandleCommand;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class SpecialRequestHandleCommand extends QueueHandleCommand
{

    public function __construct()
    {
        parent::__construct("specialRequest");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var SpecialRequestTask $task */
        $task = SpecialRequestTask::findById($input->getArgument('id'));

        if (is_null($task)) {
            $output->writeln("<error>Request with passed id was not found</error>");
            return Command::INVALID;
        }

        $request = $task->getRequest();

        if ($request->isExpired()) {
            $task->delete();
            $output->writeln("<comment>Request expired and was deleted</comment>");
            return Command::INVALID;
        }

        try {
            $response = Guzzle::getInstance()->request(
                $request->getMethod(),
                $request->getUri(),
                [
                    'json' => [
                        'request' => $request->getBody(),
                        '__task' => [
                            'createdAt' => $task,
                            'attempt' => [
                                'number' => $task->getAttempt()->getNumber(),
                                'limit' => $task->getAttempt()->getLimit(),
                                'interval' => $task->getAttempt()->getInterval(),
                            ],
                        ]
                    ],
                    'timeout' => $task->getHttpTimeout(),
                ]
            );

            if ($response->getStatusCode() === $request->getSuccessCode()) {
                $task->delete();
                $output->writeln("<info>Success!</info>");
                return Command::SUCCESS;
            }

            if (in_array($response->getStatusCode(), $request->getStopCodes())) {
                $task->delete();
                $output->writeln("<comment>Received stop-code: {$response->getStatusCode()}</comment>");
                return Command::INVALID;
            }

            $task->getAttempt()->attempt($response->getStatusCode());

        } catch (BadResponseException $exception) {

            if (in_array($exception->getResponse()->getStatusCode(), $request->getStopCodes())) {
                $task->delete();
                $output->writeln("<comment>Received stop-code: {$exception->getResponse()->getStatusCode()}</comment>");
                return Command::INVALID;
            }

            $task->getAttempt()->attempt($exception->getResponse()->getStatusCode());
            $this->outputThrowable($exception, $output);

        } catch (Throwable $throwable) {
            $task->getAttempt()->attempt($throwable->getMessage());
            $this->outputThrowable($throwable, $output);
        }

        if ($task->getAttempt()->isSpent()) {
            $task->delete();
        } else {
            $task->save();
        }

        return Command::FAILURE;
    }


    private function outputThrowable(Throwable $throwable, OutputInterface $output): void
    {
        $output->writeln("<error>{$throwable->getMessage()}</error>");
        $output->writeln("<error>{$throwable->getTraceAsString()}</error>");
    }

}