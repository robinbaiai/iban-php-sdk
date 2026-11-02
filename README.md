# IBAN V2 PHP SDK

覆盖当前 11 个对外接口，使用 PHP 7.4+，需要 PHP 的 cURL、JSON、hash 扩展。没有第三方 PHP 库依赖；**不需要安装 Composer**。也可以选择用 Composer 管理自动加载。

SDK 对应 IBAN V2 对外 API 契约 v0.46，覆盖交易中的 `reference` 和 `end_to_end_id`。各环境支持的字段和版本请以实际部署情况为准。完整字段和业务状态请配合 API 文档阅读；当前契约使用 `/api/v2`。

## 直接引用

复制整个目录到调用方项目，引用 `autoload.php`，不需要运行任何安装命令：

```php
require '/path/to/iban-php-sdk/autoload.php';

use YabandPay\Iban\Client;

$client = new Client(
    getenv('IBAN_API_ORIGIN'), // 例：http://127.0.0.1:8002，地址不包含 /api/v2
    getenv('IBAN_CLIENT_ID'),  // API Account token
    getenv('IBAN_SECRET_KEY')  // API Account secret_key
);
$response = $client->getAccount('YOUR_IBAN');
$account = $response->data();
```

代码只在可信服务端运行；非本地地址必须使用 HTTPS。测试与生产的地址和密钥分别管理，系统时钟保持准确。查看 [完整调用样例](examples/example.php)，其中的请求默认均已注释。

## 可选：Composer

注册到 Packagist 后，可在调用方项目执行：

```sh
composer require yabandpay/iban-php-sdk:^0.1
```

接着引用项目的 `vendor/autoload.php`。未注册时也可以把本目录配置为 Composer `path` 仓库，或按上文直接引用 `autoload.php`。

## 接口方法

| 方法 | 对应 API | 权限 |
| --- | --- | --- |
| `createAccount($body)` | POST `/api/v2/accounts` | `account.create` |
| `updateAccount($iban, $body)` | PATCH `/api/v2/accounts/{iban}` | `account.update` |
| `getAccount($iban)` | GET `/api/v2/accounts/{iban}` | `account.query` |
| `listAccounts($query)` | GET `/api/v2/accounts` | `account.batch_query` |
| `createPayment($body)` | POST `/api/v2/payments` | `transaction.create` |
| `cancelPayment($identity)` | POST `/api/v2/payments/cancellations` | `transaction.cancel` |
| `commitPayment($identity)` | POST `/api/v2/payments/commit` | `transaction.commit` |
| `getPayment($query)` | GET `/api/v2/payments/query` | `transaction.query` |
| `listPayments($query)` | GET `/api/v2/payments` | `transaction.batch_query` |
| `freezeFunds($body)` | POST `/api/v2/funds/freezes` | `account.freeze` |
| `unfreezeFunds($freezeId)` | POST `/api/v2/funds/unfreezes` | `account.unfreeze` |

参数使用契约原有字段名。账户批查可传 `['ibans' => ['IBAN1', 'IBAN2']]`，SDK 转为逗号分隔，最多 100 个。交易批查必须传 1–100 个本方账户，例如：

```php
$query = [
    'ibans' => ['YOUR_IBAN_1', 'YOUR_IBAN_2'],
    'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-30T00:00:00Z',
    'amount' => 100, 'currency' => 'EUR', 'limit' => 100
];
$page = $client->listPayments($query);
// 后续页保留所有筛选条件：$query['cursor'] = $page->meta()['next_cursor'];
```

SDK 把交易批查 `ibans` 和可选的 `payment_ids` PHP 列表编码为 JSON 字符串；也接受已编码字符串。每次最多 50 个 `payment_ids`；`amount` 为精确匹配的整数最小单位，传入时 `currency` 必填且当前为 EUR。其他 Query 值传字符串或整数：`is_commit` 用字符串 `true` 或 `false`。分页时保留筛选条件，将 `meta()['next_cursor']` 原值作为下一页 `cursor`；调用方自行决定何时继续。

开户可选 `customer_id`、`customer_identifier`、`same_name_only`、`remark`。`customer_id` 可传 PHP 整数、字符串或 null；SDK 把整数转成十进制字符串发送，字符串原样保留，因此 `123` 和 `'123'` 等价，`'000123'` 保留前导零且不同。不要传浮点、布尔、数组或对象；超过 PHP 可表示整数范围的编号务必传字符串，避免精度损失。服务端返回 `customer_id` 为字符串，省略/null/空串返回空串，非空最长 100 字符。

`customer_identifier` 仍仅支持字符串或 null，最长 100 字符；`same_name_only` 必须为 JSON 布尔值，`remark` 最多 255 字符。历史开户或更新的幂等响应可能没有这些字段，需要当前完整信息时调用 `getAccount()`。账户更新仅支持 `alternative_name` 和 `status`，不可通过更新接口修改上述开户字段；不要传旧字段 `creditor_identifier`。

## 返回与异常

成功返回 `Response` 对象：`data()`、`meta()`、`httpStatus()`、`requestId()`、`responseId()`。不会自动输出账户或交易数据。

- `ApiException`：收到包含规范 `error.code` 和 `error.message` 的错误响应，可读取 `httpStatus()`、`apiCode()`、`apiMessage()`、`requestId()`、`responseId()`、`retryAfter()` 和 `allow()`；最后两项是 HTTP 响应头原值，未提供时为 null。按 `apiCode()` 判断，`apiMessage()` 供阅读字段约束，不按全文匹配。5xx 等错误不一定说明业务没有执行，应核查原业务状态。
- `TransportException`：网络异常、无规范错误外壳或响应无法解析，业务结果可能未知；可读取 `requestId()`、`curlCode()`、`httpStatus()`、`retryAfter()` 和 `allow()`，没有对应信息时状态或头为 null。不要用新业务单号重新提交。
- 参数、配置或 JSON 编码错误会抛出 PHP 异常；调用前保存业务数据并由业务系统统一处理。

可传第四个构造参数作为 `callable(string $event, array $fields)` 日志回调，第五个参数为超时秒数，默认 30。SDK 不默认写日志，回调只接收脱敏跟踪信息，不包含 token、secret、签名、请求正文或账户号码。调用方仍须保护自行记录的响应数据。

## 付款及资金

每次 HTTP 请求生成新的请求 ID、时间戳和 nonce；`payment_id`、开户 `idempotency_key`、`freeze_id` 等是调用方需要持久保存的业务标识。付款前保存完整原始请求和订单号，遇到未知结果按原 `payment_id` 查询及对账。SDK 不自动重试、不自动改变订单号、不自动提交或取消。

HTTP 200/201 表示请求成功或付款指令已受理，调用方仍须逐笔读取 `data.status`。`rejected` 是明确拒绝；`exception` 表示结果未知或处理阻断，应读取 `status_reason` 并查询原单或联系支持。`payment_result_unknown` 不是确定失败。其余状态 `status_reason` 为 null；最新 Payment 不返回原 `failure_code` 和 `failure_message`。返回的 `iban` 是该记录的本方账户；内部转账双方均在批查集合时可能返回一条出款和一条入款，两条可能共用 `lifecycle_id`，要按 `iban` 和 `direction` 区分，不将其当成两笔转账。字段可从 `Response::data()` 获取；SDK 不推断资金是否释放。

最新 Payment 查询、写操作响应及新 Webhook 的 `data` 包含 `reference` 和 `end_to_end_id`。SDK 返回服务端原值，不转换、不截断：

- `reference` 是该方向原单保存的附言，入款为 string/null、最多 255 字符；新建出款请求的 `reference` 仍必填、最长 140 字符。
- `end_to_end_id` 是该方向原单保存的端到端编号，入款最多 191 字符、出款最多 35 字符，可能为 string/null。空字符串、`'0'` 和 `'NOTPROVIDED'` 都保留原值；`NOTPROVIDED` 不应被当作可用的唯一银行标识。
- 历史 Webhook 或历史幂等响应可能没有这两个字段；不要把**缺少字段**误当成明确的 null。需要当前原单值时，可在相应 API Account 有 `transaction.query` 权限的前提下，用 `getPayment(['lifecycle_id' => $id])` 查询；存在内部转账双方视角时，可加可选的本方 `iban`。

调用方按 IBAN、金额和币种匹配入款订单时，仍需校验 `direction=inbound` 和 `status=received`，并按 `lifecycle_id` 去重。附言和端到端编号如被用作关联依据，应先确认发送方和渠道实际提供了可区分的值，不可根据缺失值猜测或生成。

金额为最小单位整数：EUR `100` 是 1 欧元。`is_commit` 默认 `true`；`false` 创建付款和预留，须传固定的 `commit_deadline`，首次创建时严格在未来且不超过 30 天。到期不自动释放，按规则取消；提交成功不代表银行已执行。批量交易的 `from` 包含、`to` 不包含，跨度大于 0 且最多 100 天。

## Webhook 验签

`WebhookVerifier` 校验 API Account 的 Client ID、时间窗、事件 ID、原始正文和 `v1=` HMAC 签名，并返回解码后的事件数组；`WebhookException::httpStatus()` 表示认证失败或正文无效。使用 Laravel 的 `header()` 取单个字符串、`getContent()` 获取原始字节；不要使用 `headers->all()` 的数组作为签名参数。参见 [Laravel 接入示例](examples/webhook-laravel.php)。

```php
use YabandPay\Iban\WebhookVerifier;

$verifier = new WebhookVerifier($clientId, $secret);
$event = $verifier->verify($rawBody, $headerClientId, $headerEventId, $headerTimestamp, $headerSignature);
// 先将事件持久化、按 (client_id, event_id) 去重，成功后才向 IBAN 返回 2xx。
```

Webhook 验签的五行原文与 API 请求签名的七行原文不同。一个 API Account 对应一组 token 和 secret；多个账号共用接收地址时，按 Client ID 从受保护的配置查找对应凭据后验签。通知可能重复和乱序：同一事件 ID 重试正文不变；事件序号按 API Account、方向和原单分别递增。`payment.inbound.updated` 与 `payment.outbound.updated` 各自处理，`data.status_reason` 是最新的公开状态原因。验签本身不存储、不去重、不执行任何业务；持久化失败应返回非 2xx。
