<?php
declare(strict_types=1);
namespace YabandPay\Iban;

/** 无法确认服务端结果，包括网络错误或无法解析的响应。 */
final class TransportException extends \RuntimeException
{
    private $requestId;
    private $curlCode;
    private $httpStatus;
    private $retryAfter;
    private $allow;
    public function __construct(string $message, string $requestId, int $curlCode = 0, ?int $httpStatus = null, ?string $retryAfter = null, ?string $allow = null)
    {
        parent::__construct($message);
        $this->requestId = $requestId;
        $this->curlCode = $curlCode;
        $this->httpStatus = $httpStatus;
        $this->retryAfter = $retryAfter;
        $this->allow = $allow;
    }
    public function requestId(): string { return $this->requestId; }
    public function curlCode(): int { return $this->curlCode; }
    public function httpStatus(): ?int { return $this->httpStatus; }
    public function retryAfter(): ?string { return $this->retryAfter; }
    public function allow(): ?string { return $this->allow; }
}
