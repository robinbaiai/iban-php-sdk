<?php
declare(strict_types=1);
namespace YabandPay\Iban;

/** 验签并解析通知；持久化、事件去重及业务处理由接收方负责。 */
final class WebhookVerifier
{
    private $clientId;
    private $secret;
    private $window;

    public function __construct(string $clientId, string $secret, int $windowSeconds = 300)
    {
        if ($clientId === '' || $secret === '' || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Webhook API Account 凭据或时间窗无效。');
        }
        $this->clientId = $clientId;
        $this->secret = $secret;
        $this->window = $windowSeconds;
    }

    /** 所有参数均为原始 HTTP 字符串；Laravel 使用 header() 和 getContent() 获取。 */
    public function verify(string $rawBody, string $clientId, string $eventId, string $timestamp, string $signature): array
    {
        if (!hash_equals($this->clientId, $clientId)
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $eventId)
            || !preg_match('/\A[0-9]{1,16}\z/D', $timestamp)
            || abs(time() - (float) $timestamp) > $this->window
            || !preg_match('/\Av1=[0-9a-f]{64}\z/D', $signature)) {
            throw new WebhookException('Webhook 认证失败。', 401);
        }
        // 五行原文与 API 请求的七行签名不同；正文必须是收到的原始字节。
        $message = implode("\n", ['ibanv2-webhook-v1', $clientId, $eventId, $timestamp, hash('sha256', $rawBody)]);
        $expected = 'v1=' . hash_hmac('sha256', $message, $this->secret);
        if (!hash_equals($expected, $signature)) { throw new WebhookException('Webhook 认证失败。', 401); }
        try {
            $object = json_decode($rawBody, false, 64, JSON_THROW_ON_ERROR);
            $data = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) { throw new WebhookException('Webhook JSON 无效。', 400); }
        if (!is_object($object) || !isset($object->data) || !is_object($object->data) || !is_array($data)
            || ($data['event_id'] ?? null) !== $eventId || !is_string($data['event_type'] ?? null)
            || !is_int($data['event_sequence'] ?? null) || $data['event_sequence'] < 1) {
            throw new WebhookException('Webhook 正文格式无效。', 400);
        }
        return $data;
    }
}
