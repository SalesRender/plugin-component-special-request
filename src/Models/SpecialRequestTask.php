<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 15.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models;

use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Leadvertex\Plugin\Components\Db\ModelInterface;
use Leadvertex\Plugin\Components\Guzzle\Guzzle;
use Leadvertex\Plugin\Components\Queue\Models\Task\Task;
use Leadvertex\Plugin\Components\Queue\Models\Task\TaskAttempt;
use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Components\SpecialRequest;

class SpecialRequestTask extends Task implements ModelInterface
{

    protected SpecialRequest $request;

    protected int $httpTimeout;

    public function __construct(SpecialRequest $request, int $attemptLimit = null, int $attemptTimeout = 60, int $httpTimeout = 30)
    {
        $limitByExpire = $request->getExpireAt() ? round(($request->getExpireAt() - time()) / 60) : null;
        parent::__construct(new TaskAttempt($attemptLimit ?? $limitByExpire ?? 24 * 60, $attemptTimeout));
        $this->request = $request;
        $this->httpTimeout = $httpTimeout;
    }

    /**
     * @return SpecialRequest
     */
    public function getRequest(): SpecialRequest
    {
        return $this->request;
    }

    public function send(): bool
    {
        if ($this->request->isExpired()) {
            $this->createFailedLog(FailedRequestLog::REASON_EXPIRED);
            $this->delete();
            return false;
        }

        try {
            $response = Guzzle::getInstance()->request(
                $this->request->getMethod(),
                $this->request->getUri(),
                [
                    'json' => [
                        'request' => $this->request->getBody(),
                        '__task' => [
                            'createdAt' => $this->createdAt,
                            'attempt' => [
                                'number' => $this->attempt->getNumber(),
                                'limit' => $this->attempt->getLimit(),
                                'interval' => $this->attempt->getInterval(),
                            ],
                        ]
                    ],
                    'timeout' => $this->httpTimeout,
                ]
            );
            $this->attempt->attempt($response->getStatusCode());
            if ($isSuccess = $response->getStatusCode() === $this->request->getSuccessCode()) {
                $this->delete();
                return true;
            }
        } catch (BadResponseException $exception) {
            $isSuccess = false;
            $this->attempt->attempt($exception->getResponse()->getStatusCode());
        } catch (Exception $exception) {
            $isSuccess = false;
            $this->attempt->attempt('');
        }

        if (!$isSuccess) {
            if (in_array($this->attempt->getLog(), $this->request->getStopCodes())) {
                $this->createFailedLog(FailedRequestLog::REASON_STOP_CODE);
                $this->delete();
                return false;
            }

            if ($this->attempt->isSpent()) {
                $this->createFailedLog(FailedRequestLog::REASON_ATTEMPT);
                $this->delete();
                return false;
            }
        }

        $this->save();
        return $isSuccess;
    }

    public function save(): void
    {
        $isNew = $this->isNewModel();
        parent::save();

        if ($isNew && $_ENV['LV_PLUGIN_DEBUG']) {
            $this->send();
        }
    }

    protected function createFailedLog(int $reason): void
    {
        $log = new FailedRequestLog(
            $this->companyId,
            $this->pluginAlias,
            $this->pluginId,
            $this->createdAt,
            $this->request->getMethod(),
            $this->request->getUri(),
            $this->request->getBody(),
            $this->attempt->getLastTime(),
            $this->attempt->getNumber(),
            $this->attempt->getLog(),
            $reason,
        );
        $log->save();
    }

    protected static function beforeWrite(array $data): array
    {
        $data = parent::beforeWrite($data);
        /** @var SpecialRequest $request */
        $request = $data['request'];
        $data['request'] = json_encode([
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'body' => $request->getBody(),
            'expireAt' => $request->getExpireAt(),
            'successCode' => $request->getSuccessCode(),
            'stopCodes' => $request->getStopCodes(),
        ]);
        return $data;
    }

    protected static function afterRead(array $data): array
    {
        $data = parent::afterRead($data);
        $request = new SpecialRequest(
            $data['request']['method'],
            $data['request']['uri'],
            $data['request']['body'],
            $data['request']['expireAt'],
            $data['request']['successCode'],
            $data['request']['stopCodes'] ?? [],
        );
        $data['request'] = $request;
        return $data;
    }

    public static function schema(): array
    {
        return array_merge(parent::schema(), [
            'request' => ['TEXT', 'NOT NULL'],
            'httpTimeout' => ['INT', 'NOT NULL'],
        ]);
    }
}