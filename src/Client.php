<?php
declare(strict_types=1);
namespace YabandPay\Iban;

/** 仅处理通信与协议。业务单持久化、对账和重试决策由调用方负责。 */
final class Client
{
    private $origin;
    private $clientId;
    private $secret;
    private $logger;
    private $timeout;

    /** $origin 仅为协议、主机及可选端口；$logger 可接收 (事件名称, 脱敏字段数组)。 */
    public function __construct(string $origin, string $clientId, string $secret, ?callable $logger = null, int $timeout = 30)
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
            !in_array($parts['scheme'], ['http', 'https'], true) || isset($parts['user']) ||
            isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            (isset($parts['path']) && rtrim($parts['path'], '/') !== '')) {
            throw new \InvalidArgumentException('地址仅允许协议、主机和可选端口。');
        }
        if ($parts['scheme'] !== 'https' && !in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]', '::1'], true)) {
            throw new \InvalidArgumentException('非本地地址必须使用 HTTPS。');
        }
        if ($clientId === '' || $secret === '' || preg_match('/[\r\n]/', $clientId) || $timeout < 1) {
            throw new \InvalidArgumentException('API 凭据或超时设置无效。');
        }
        if (!function_exists('curl_init')) { throw new \RuntimeException('需要 PHP cURL 扩展。'); }
        $this->origin = rtrim($origin, '/'); $this->clientId = $clientId; $this->secret = $secret;
        $this->logger = $logger; $this->timeout = $timeout;
    }

    public function createAccount(array $body): Response
    {
        // customer_id 可以是整数或字符串；统一发送字符串，兼容尚未部署整数支持的服务器。
        // 只转换真正的整数，保留字符串前导零；拒绝浮点和布尔等，避免业务编号被悄悄改写。
        if (array_key_exists('customer_id', $body) && $body['customer_id'] !== null) {
            if (is_int($body['customer_id'])) {
                $body['customer_id'] = (string) $body['customer_id'];
            } elseif (!is_string($body['customer_id'])) {
                throw new \InvalidArgumentException('customer_id 必须是字符串、整数或 null；超大编号请用字符串。');
            }
        }
        return $this->request('POST', '/accounts', [], $body);
    }
    public function updateAccount(string $iban, array $body): Response { return $this->request('PATCH', '/accounts/' . rawurlencode($iban), [], $body); }
    public function getAccount(string $iban): Response { return $this->request('GET', '/accounts/' . rawurlencode($iban)); }
    public function listAccounts(array $query = []): Response
    {
        // 账户批查的 ibans 是逗号分隔字符串，也可传 PHP 数组由 SDK 编码。
        if (isset($query['ibans']) && is_array($query['ibans'])) {
            self::stringList($query['ibans'], 1, 100, 'ibans');
            foreach ($query['ibans'] as $iban) {
                if (strpos($iban, ',') !== false) { throw new \InvalidArgumentException('IBAN 不能包含逗号。'); }
            }
            $query['ibans'] = implode(',', $query['ibans']);
        }
        return $this->request('GET', '/accounts', $query);
    }
    public function createPayment(array $body): Response { return $this->request('POST', '/payments', [], $body); }
    /** 二选一：['payment_id' => ...] 或 ['lifecycle_id' => ...]。 */
    public function cancelPayment(array $identity): Response { return $this->request('POST', '/payments/cancellations', [], $identity); }
    public function commitPayment(array $identity): Response { return $this->request('POST', '/payments/commit', [], $identity); }
    public function getPayment(array $query): Response { return $this->request('GET', '/payments/query', $query); }
    public function listPayments(array $query): Response
    {
        // 交易批查的 ibans 与 payment_ids 是 JSON 数组字符串；允许直接传 PHP 数组。
        foreach (['ibans' => [1, 100], 'payment_ids' => [0, 50]] as $field => $bounds) {
            if (isset($query[$field]) && is_array($query[$field])) {
                self::stringList($query[$field], $bounds[0], $bounds[1], $field);
                $query[$field] = json_encode($query[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }
        return $this->request('GET', '/payments', $query);
    }
    public function freezeFunds(array $body): Response { return $this->request('POST', '/funds/freezes', [], $body); }
    public function unfreezeFunds(string $freezeId): Response { return $this->request('POST', '/funds/unfreezes', [], ['freeze_id' => $freezeId]); }

    private function request(string $method, string $endpoint, array $query = [], ?array $body = null): Response
    {
        foreach ($query as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('Query 值必须是字符串或整数；is_commit 使用 true/false 字符串。');
            }
        }
        ksort($query, SORT_STRING);
        $canonical = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        // 转换为对象确保空 Body 数组也编码为 {}，而不是 []。
        $raw = $body === null ? '' : json_encode((object) $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = '/api/v2' . $endpoint;
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $requestId = self::uuid();
        $message = implode("\n", [$this->clientId, $method, $path, $canonical, hash('sha256', $raw), $timestamp, $nonce]);
        $headers = ['Accept: application/json', 'X-Client-Id: ' . $this->clientId,
            'X-Request-Timestamp: ' . $timestamp, 'X-Request-Nonce: ' . $nonce,
            'X-Request-Signature: ' . hash_hmac('sha256', $message, $this->secret), 'X-Request-Id: ' . $requestId];
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; }
        $url = $this->origin . $path . ($canonical === '' ? '' : '?' . $canonical);
        $label = preg_replace('~^/accounts/.*$~', '/accounts/{iban}', $endpoint);
        $this->log('request_started', ['request_id' => $requestId, 'method' => $method, 'endpoint' => $label]);
        $curl = curl_init($url);
        if ($curl === false) { throw new TransportException('无法初始化 HTTP 请求。', $requestId); }
        $responseHeaders = [];
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout), CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (stripos($line, 'HTTP/') === 0) { $responseHeaders = []; }
                $pair = explode(':', $line, 2);
                if (count($pair) === 2) {
                    $name = strtolower(trim($pair[0]));
                    if (in_array($name, ['retry-after', 'allow'], true)) { $responseHeaders[$name] = trim($pair[1]); }
                }
                return strlen($line);
            }]);
        if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $raw); }
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlCode = curl_errno($curl);
        curl_close($curl);
        if ($result === false) {
            $this->log('transport_unknown', ['request_id' => $requestId, 'curl_code' => $curlCode]);
            throw new TransportException('网络结果未知，请保留原业务请求并查询。', $requestId, $curlCode);
        }
        $retryAfter = $responseHeaders['retry-after'] ?? null;
        $allow = $responseHeaders['allow'] ?? null;
        try { $object = json_decode($result, false, 512, JSON_THROW_ON_ERROR); $json = json_decode($result, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) {
            $this->log('invalid_response', ['request_id' => $requestId, 'http_status' => $status]);
            throw new TransportException('响应无法解析，请核查原业务状态。', $requestId, 0, $status, $retryAfter, $allow);
        }
        if (!is_object($object) || !is_array($json)) { throw new TransportException('响应格式异常，请核查原业务状态。', $requestId, 0, $status, $retryAfter, $allow); }
        $responseId = isset($json['response_id']) && is_string($json['response_id']) ? $json['response_id'] : null;
        $errorCode = isset($json['error']['code']) && is_string($json['error']['code']) ? $json['error']['code'] : null;
        $errorMessage = isset($json['error']['message']) && is_string($json['error']['message']) ? $json['error']['message'] : null;
        $this->log('response_received', ['request_id' => $requestId, 'response_id' => $responseId, 'http_status' => $status, 'error_code' => $errorCode]);
        if (array_key_exists('error', $json) && $errorCode !== null && $errorMessage !== null) {
            throw new ApiException($status, $errorCode, $requestId, $responseId, $errorMessage, $retryAfter, $allow);
        }
        if ($status < 200 || $status >= 300 || array_key_exists('error', $json)) {
            throw new TransportException('HTTP 错误或错误格式异常，请核查原业务状态。', $requestId, 0, $status, $retryAfter, $allow);
        }
        if (!array_key_exists('data', $json) || !isset($json['meta']) || !is_array($json['meta'])) {
            throw new TransportException('响应缺少必要字段，请核查原业务状态。', $requestId, 0, $status, $retryAfter, $allow);
        }
        return new Response($status, $requestId, $responseId, $json['data'], $json['meta']);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function stringList(array $values, int $min, int $max, string $name): void
    {
        $count = count($values);
        if ($count < $min || $count > $max || ($count > 0 && array_keys($values) !== range(0, $count - 1))) {
            throw new \InvalidArgumentException($name . ' 必须是包含 ' . $min . ' 至 ' . $max . ' 项的数组。');
        }
        foreach ($values as $value) {
            if (!is_string($value)) { throw new \InvalidArgumentException($name . ' 的每一项必须是字符串。'); }
        }
    }

    private function log(string $event, array $fields): void
    {
        if ($this->logger !== null) {
            // 日志系统故障不能改变已发送请求的结果或诱发调用方重复发起业务。
            try { ($this->logger)($event, $fields); } catch (\Throwable $ignored) { }
        }
    }
}
