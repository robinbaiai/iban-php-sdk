<?php
declare(strict_types=1);

// 直接使用：复制 SDK 目录后引用此文件。使用 Composer 时换成 vendor/autoload.php。
require __DIR__ . '/../autoload.php';

use YabandPay\Iban\ApiException;
use YabandPay\Iban\Client;
use YabandPay\Iban\TransportException;

// 密钥由调用方在服务器安全配置；不写在代码或浏览器中。
$origin = getenv('IBAN_API_ORIGIN');
$clientId = getenv('IBAN_CLIENT_ID');
$secret = getenv('IBAN_SECRET_KEY');
if (!$origin || !$clientId || !$secret) { fwrite(STDERR, "请设置 IBAN_API_ORIGIN、IBAN_CLIENT_ID、IBAN_SECRET_KEY。\n"); exit(1); }

// 可选日志回调：SDK 仅传跟踪号、接口、HTTP 状态和错误代码等摘要。
$logger = static function (string $event, array $fields): void {
    error_log(json_encode(['time' => gmdate('c'), 'event' => $event] + $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};
$client = new Client($origin, $clientId, $secret, $logger);

// 此文件默认不发请求。复制下面需要的代码到业务系统并替换数据，然后主动调用。
// 对每个新业务在调用前持久化稳定业务键及完整原始参数；下面字符串只是格式示例。
try {
    // 创建公司账户；个人传 customer_type=individual、first_name、last_name，省略 holder_name。
    // $r = $client->createAccount(['idempotency_key' => 'ACCT-001', 'customer_type' => 'legal_entity', 'holder_name' => 'Example B.V.', 'alternative_name' => [], 'customer_id' => '00001234', 'customer_identifier' => 'CUSTOMER-REF-001', 'same_name_only' => false, 'remark' => '']);
    // customer_id 可传整数 1092176670 或字符串 '1092176670'；SDK 会将整数转成字符串发送。
    // 若编号有前导零或超过 PHP 整数精确范围，务必传字符串，例如 '00001234'。
    // customer_identifier 仅支持字符串/null；same_name_only 必须是布尔值。
    // 它们不是幂等键；历史开户/更新幂等重放可能缺字段，需要当前账户资料时调用 getAccount。
    // 更新别名：省略不变，[] 或 null 清空；status 可选 inactive。
    // $r = $client->updateAccount('YOUR_IBAN', ['idempotency_key' => 'UPDATE-001', 'alternative_name' => ['Example Trading']]);
    // $r = $client->getAccount('YOUR_IBAN');
    // $r = $client->listAccounts(['ibans' => ['YOUR_IBAN'], 'limit' => 50]); // 账户最多 100 个，也可不传 ibans。

    // 创建付款：amount 单位是分；is_commit=false 时传固定 commit_deadline 等待提交。
    // $body = ['payment_id' => 'ORDER-001', 'source_iban' => 'YOUR_IBAN', 'scheme' => 'SCT_INST', 'amount' => 100, 'currency' => 'EUR', 'beneficiary' => ['name' => 'Example', 'iban' => 'RECIPIENT_IBAN', 'bic' => 'RECIPIENT_BIC'], 'reference' => 'Invoice 001', 'is_commit' => true];
    // 将 $body 和 payment_id 存入自己的数据库，成功持久化后再调用。
    // $r = $client->createPayment($body);
    // $r = $client->commitPayment(['payment_id' => 'ORDER-001']);
    // $r = $client->cancelPayment(['payment_id' => 'ORDER-001']);
    // $r = $client->getPayment(['payment_id' => 'ORDER-001']); // 入款也可用 lifecycle_id 查询。
    // $r = $client->listPayments(['ibans' => ['YOUR_IBAN'], 'from' => '2026-10-28T00:00:00Z', 'to' => '2026-10-29T00:00:00Z', 'amount' => 100, 'currency' => 'EUR', 'limit' => 50]);
    // 交易批查的 ibans 为 1–100 个账户，SDK 会转成 JSON 字符串；payment_ids 也可直接传 PHP 数组。
    // 下一页在保持其余条件不变的情况下传入 $r->meta()['next_cursor']。
    // $r = $client->freezeFunds(['freeze_id' => 'FREEZE-001', 'iban' => 'YOUR_IBAN', 'freeze_amount' => 100, 'currency' => 'EUR']);
    // $r = $client->unfreezeFunds('FREEZE-001');

    // 使用响应对象：$r->data()、$r->meta()、$r->httpStatus()、$r->requestId()、$r->responseId()。
    // 数据可能包含姓名、账户和金额，不要直接写入普通日志。
    // 每个付款逐条判断；outbound 的 iban 是付款账户，inbound 的 iban 是实际入账账户。
    // foreach ($r->data() as $payment) {
    //     $status = $payment['status']; $statusReason = $payment['status_reason'];
    //     // 最新交易对象包含原值 reference 和 end_to_end_id；旧快照可能缺少字段。
    //     // 不要用 empty()，否则 '0' 会被误判为空；不要 trim、补造或把 NOTPROVIDED 当作唯一编号。
    //     $hasReference = array_key_exists('reference', $payment);
    //     $reference = $hasReference ? $payment['reference'] : null;
    //     $hasEndToEndId = array_key_exists('end_to_end_id', $payment);
    //     $endToEndId = $hasEndToEndId ? $payment['end_to_end_id'] : null;
    //     // 缺字段时可用 lifecycle_id 查询原单（需要 transaction.query 权限）。
    //     if ($status === 'rejected') { /* 明确拒绝：核对原单与资金，再由业务决定 */ }
    //     elseif ($status === 'exception') { /* 结果未知或处理阻断：查询原单或联系支持 */ }
    //     elseif ($status === 'executed' || $status === 'received') { /* 按出入款方向分别处理 */ }
    //     else { /* 根据状态表继续处理；不能仅凭 HTTP 200/201 认定已执行 */ }
    // }
} catch (TransportException $e) {
    // 结果未知：保留原订单、请求和预留；按原 payment_id 查询，不使用新单号盲目重发。
    error_log('请求结果未知 request_id=' . $e->requestId());
} catch (ApiException $e) {
    // 有 HTTP 错误响应；5xx 及语义不明的错误仍应按原业务单查询并核对。
    error_log('API 错误 http=' . $e->httpStatus() . ' code=' . $e->apiCode() . ' request_id=' . $e->requestId());
    // $e->apiMessage() 是可读的字段提示，不按全文匹配；$e->retryAfter()、$e->allow() 是可选 HTTP 头。
}
