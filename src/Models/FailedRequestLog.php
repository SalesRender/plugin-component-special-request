<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 16.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Models;

use Leadvertex\Plugin\Components\Db\Model;

class FailedRequestLog extends Model
{

    const REASON_EXPIRED = 100;
    const REASON_ATTEMPT = 200;
    const REASON_STOP_CODE = 300;

    protected ?string $companyId;
    protected ?string $pluginId;
    protected int $createdAt;
    protected string $method;
    protected string $uri;
    protected string $body;
    protected ?int $attemptAt;
    protected int $attemptNumber;
    protected ?int $attemptCode;
    protected int $reason;

    public function __construct(
        string $companyId,
        string $pluginId,
        int $createdAt,
        string $method,
        string $uri,
        string $body,
        int $attemptAt,
        int $attemptNumber,
        int $attemptCode,
        int $reason
    )
    {
        $this->companyId = $companyId;
        $this->pluginId = $pluginId;
        $this->createdAt = $createdAt;
        $this->method = $method;
        $this->uri = $uri;
        $this->body = $body;
        $this->attemptAt = $attemptAt;
        $this->attemptNumber = $attemptNumber;
        $this->attemptCode = $attemptCode;
        $this->reason = $reason;
    }

    public static function schema(): array
    {
        return [
            'companyId' => ['VARCHAR(255)'],
            'pluginId' => ['VARCHAR(255)'],
            'createdAt' => ['INT', 'NOT NULL'],
            'method' => ['VARCHAR(10)', 'NOT NULL'],
            'uri' => ['VARCHAR(512)', 'NOT NULL'],
            'body' => ['TEXT', 'NOT NULL'],
            'attemptAt' => ['INT'],
            'attemptNumber' => ['INT'],
            'attemptCode' => ['INT'],
            'reason' => ['INT', 'NOT NULL'],
        ];
    }
}