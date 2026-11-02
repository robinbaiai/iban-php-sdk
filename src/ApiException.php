<?php
declare(strict_types=1);
namespace YabandPay\Iban;

/** HTTP 已收到错误响应；5xx 等状态下业务结果仍可能需要查询核对。 */
final class ApiException extends \RuntimeException
{
    private $httpStatus;
    private $apiCode;
    private $requestId;
    private $responseId;
    private $apiMessage;
    private $retryAfter;
    private $allow;

    public function __construct(int $status, ?string $code, string $requestId, ?string $responseId, ?string $apiMessage = null, ?string $retryAfter = null, ?string $allow = null)
    {
        parent::__construct('IBAN API 返回 HTTP ' . $status . '，请根据错误码和原业务状态处理。');
        $this->httpStatus = $status;
        $this->apiCode = $code;
        $this->requestId = $requestId;
        $this->responseId = $responseId;
        $this->apiMessage = $apiMessage;
        $this->retryAfter = $retryAfter;
        $this->allow = $allow;
    }
    public function httpStatus(): int { return $this->httpStatus; }
    public function apiCode(): ?string { return $this->apiCode; }
    public function requestId(): string { return $this->requestId; }
    public function responseId(): ?string { return $this->responseId; }
    /** 提示供阅读；程序按 apiCode 判断，不按说明全文匹配。 */
    public function apiMessage(): ?string { return $this->apiMessage; }
    public function retryAfter(): ?string { return $this->retryAfter; }
    public function allow(): ?string { return $this->allow; }
}
