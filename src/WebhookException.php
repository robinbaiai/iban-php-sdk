<?php
declare(strict_types=1);
namespace YabandPay\Iban;

/** 通知认证或正文校验失败；不包含凭据、签名或正文。 */
final class WebhookException extends \RuntimeException
{
    private $httpStatus;
    public function __construct(string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
    public function httpStatus(): int { return $this->httpStatus; }
}
