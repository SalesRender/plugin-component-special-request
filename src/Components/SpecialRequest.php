<?php
/**
 * Created for plugin-component-request-dispatcher
 * Date: 15.07.2021
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\SpecialRequestDispatcher\Components;


class SpecialRequest
{

    protected string $method;

    protected string $uri;

    protected string $body;

    protected ?int $expireAt = null;

    protected int $successCode;

    public function __construct(string $method, string $uri, string $body, ?int $expireAt, int $successCode)
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->body = $body;
        $this->expireAt = $expireAt;
        $this->successCode = $successCode;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getExpireAt(): ?int
    {
        return $this->expireAt;
    }

    public function isExpired(): bool
    {
        if ($this->expireAt === null) {
            return false;
        }

        return $this->expireAt < time();
    }
    public function getSuccessCode(): int
    {
        return $this->successCode;
    }

}