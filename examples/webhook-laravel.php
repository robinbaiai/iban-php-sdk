<?php
declare(strict_types=1);

// Laravel 接入示例：路由调用 receiveIbanWebhook，并注入你自己实现的持久化函数。
// 本文件不注册路由、不发送请求、不自动返回 2xx。
require_once __DIR__ . '/../autoload.php';

use Illuminate\Http\Request;
use YabandPay\Iban\WebhookException;
use YabandPay\Iban\WebhookVerifier;

/**
 * $storeOnce($clientId, $eventId, $rawBody, $payload)：
 * 在数据库事务内按 (clientId, eventId) 唯一存储原始正文和事件；
 * 重复事件正文相同时安全返回，正文冲突或数据库故障时抛出异常。
 * 禁止将这几个参数或凭据写入普通日志。
 */
function receiveIbanWebhook(Request $request, callable $storeOnce)
{
    $configuredClient = getenv('IBAN_CLIENT_ID');
    $secret = getenv('IBAN_SECRET_KEY');
    if (!$configuredClient || !$secret) { return response('', 503); }
    $client = (string) $request->header('X-Client-Id', '');
    $event = (string) $request->header('X-Webhook-Id', '');
    $timestamp = (string) $request->header('X-Webhook-Timestamp', '');
    $signature = (string) $request->header('X-Webhook-Signature', '');
    $raw = $request->getContent(); // 原始正文，不要先 json_encode。

    try {
        $payload = (new WebhookVerifier($configuredClient, $secret))->verify($raw, $client, $event, $timestamp, $signature);
    } catch (WebhookException $e) {
        return response('', $e->httpStatus());
    }
    // 新事件在 data 中包含原单 reference / end_to_end_id；历史事件可能缺字段。
    // 使用 array_key_exists 区分缺字段和 null；不要改写原文、猜测编号或按 'NOTPROVIDED' 认领交易。
    try {
        $storeOnce($client, $event, $raw, $payload);
    } catch (Throwable $e) {
        // 数据未可靠保存时返回非 2xx，等待发送方重试；不要输出异常详情或正文。
        return response('', 503);
    }
    // 只在事件可靠保存或确认相同事件已保存后返回 2xx，业务处理交给独立 worker。
    return response('', 204);
}
