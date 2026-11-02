<?php
declare(strict_types=1);
namespace YabandPay\Iban;

/** 将 HTTP 跟踪信息与 API data/meta 一起返回给调用方。 */
final class Response
{
    private $status;
    private $requestId;
    private $responseId;
    private $data;
    private $meta;
    public function __construct(int $status, string $requestId, ?string $responseId, $data, array $meta)
    {
        $this->status = $status; $this->requestId = $requestId; $this->responseId = $responseId;
        $this->data = $data; $this->meta = $meta;
    }
    public function httpStatus(): int { return $this->status; }
    public function requestId(): string { return $this->requestId; }
    public function responseId(): ?string { return $this->responseId; }
    public function data() { return $this->data; }
    public function meta(): array { return $this->meta; }
}
