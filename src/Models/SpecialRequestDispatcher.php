<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 15.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models;

use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Leadvertex\Plugin\Components\Db\Components\Connector;
use Leadvertex\Plugin\Components\Db\Helpers\UuidHelper;
use Leadvertex\Plugin\Components\Db\Model;
use Leadvertex\Plugin\Components\Db\ModelInterface;
use Leadvertex\Plugin\Components\Guzzle\Guzzle;
use Leadvertex\Plugin\Components\SpecialRequestDispatcher\Components\SpecialRequest;

class SpecialRequestDispatcher extends Model implements ModelInterface
{

    protected ?string $companyId = null;

    protected ?string $pluginId = null;

    protected int $createdAt;

    protected SpecialRequest $request;

    protected int $attemptLimit;

    protected ?int $attemptAt = null;

    protected int $attemptNumber = 0;

    protected ?int $attemptCode = null;

    protected int $httpTimeout;

    public function __construct(SpecialRequest $request, int $attemptLimit = null, int $httpTimeout = 30)
    {
        $this->id = UuidHelper::getUuid();
        if (Connector::hasReference()) {
            $this->companyId = Connector::getReference()->getCompanyId();
            $this->pluginId = Connector::getReference()->getId();
        }
        $this->createdAt = time();
        $this->request = $request;
        $limitByExpire = $request->getExpireAt() ? round(($request->getExpireAt() - time()) / 60) : null;
        $this->attemptLimit = $attemptLimit ?? $limitByExpire ?? 24 * 60;
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
            $this->attemptAt = time();
            $this->attemptNumber++;
            $response = Guzzle::getInstance()->request(
                $this->request->getMethod(),
                $this->request->getUri(),
                [
                    'json' => [
                        'request' => $this->request->getBody(),
                        '__dispatcher' => [
                            'createdAt' => $this->createdAt,
                            'attemptNumber' => $this->attemptNumber,
                            'attemptLimit' => $this->attemptLimit,
                        ]
                    ],
                    'timeout' => $this->httpTimeout,
                ]
            );
            $this->attemptCode = $response->getStatusCode();
            if ($isSuccess = $response->getStatusCode() === $this->request->getSuccessCode()) {
                $this->delete();
                return true;
            }
        } catch (BadResponseException $exception) {
            $isSuccess = false;
            $this->attemptCode = $exception->getResponse()->getStatusCode();
        } catch (Exception $exception) {
            $isSuccess = false;
            $this->attemptCode = null;
        }

        if (!$isSuccess) {
            if (in_array($this->attemptCode, $this->request->getStopCodes())) {
                $this->createFailedLog(FailedRequestLog::REASON_STOP_CODE);
                $this->delete();
                return false;
            }

            if ($this->attemptNumber >= $this->attemptLimit) {
                $this->createFailedLog(FailedRequestLog::REASON_ATTEMPT);
                $this->delete();
                return false;
            }
        }

        $this->save();
        return $isSuccess;
    }

    protected function createFailedLog(int $reason): void
    {
        $log = new FailedRequestLog(
            $this->companyId,
            $this->pluginId,
            $this->createdAt,
            $this->request->getMethod(),
            $this->request->getUri(),
            $this->request->getBody(),
            $this->attemptAt,
            $this->attemptNumber,
            $this->attemptCode,
            $reason,
        );
        $log->save();
    }

    protected static function beforeWrite(array $data): array
    {
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
        return [
            'companyId' => ['VARCHAR(255)'],
            'pluginId' => ['VARCHAR(255)'],
            'createdAt' => ['INT', 'NOT NULL'],
            'request' => ['TEXT', 'NOT NULL'],
            'httpTimeout' => ['INT', 'NOT NULL'],
            'attemptLimit' => ['INT'],
            'attemptAt' => ['INT'],
            'attemptNumber' => ['INT'],
            'attemptCode' => ['INT'],
        ];
    }
}