<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 15.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models;

use Leadvertex\Plugin\Components\Db\ModelInterface;
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

    public function getAttempt(): TaskAttempt
    {
        return $this->attempt;
    }

    public function getHttpTimeout(): int
    {
        return $this->httpTimeout;
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
        $requestData = json_decode($data['request'], true);
        $request = new SpecialRequest(
            $requestData['method'],
            $requestData['uri'],
            $requestData['body'],
            $requestData['expireAt'],
            $requestData['successCode'],
            $requestData['stopCodes'] ?? [],
        );
        $data['request'] = $request;
        return $data;
    }

    public static function schema(): array
    {
        return array_merge(parent::schema(), [
            'request' => ['MEDIUMTEXT', 'NOT NULL'],
            'httpTimeout' => ['INT', 'NOT NULL'],
        ]);
    }
}