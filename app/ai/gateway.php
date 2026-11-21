<?php
declare(strict_types=1);

/**
 * AI 协议适配层：一套内部消息格式，适配三家原生协议。
 *
 *   openai     POST {base}/chat/completions        （DeepSeek/Kimi/通义/豆包/智谱/Ollama/vLLM 都走这个）
 *   anthropic  POST {base}/v1/messages             （x-api-key + anthropic-version）
 *   gemini     POST {base}/v1beta/models/{m}:generateContent?key=…
 *
 * 只做「发请求、取文本、取 token 用量、把错误翻成人话」，计费与业务在 billing/service 里。
 */

class AiError extends RuntimeException
{
    /** auth | rate | quota | timeout | network | bad_response | bad_config */
    public string $kind;

    public function __construct(string $kind, string $message, int $code = 0)
    {
        parent::__construct($message, $code);
        $this->kind = $kind;
    }
}

function ai_protocols(): array
{
    return ['openai' => 'OpenAI 兼容', 'anthropic' => 'Anthropic', 'gemini' => 'Google Gemini'];
}

/** 允许的协议，非法值一律当 openai */
function ai_protocol(string $p): string
{
    return isset(ai_protocols()[$p]) ? $p : 'openai';
}

/** 单次输入字符上限（settings 可调），防止把整库倒进去 */
function ai_max_input(): int
{
    $n = (int)setting_get('ai_max_input', '8000');
    return $n >= 500 ? min($n, 200000) : 8000;
}

/** 模型行 → 明文密钥（库里是 secret_encrypt 后的，可能为旧版明文） */
function ai_model_secret(array $m): string
{
    return secret_decrypt((string)($m['api_key'] ?? ''));
}

/** 对外展示用的模型信息（绝不带密钥） */
function ai_model_public(array $m): array
{
    return [
        'id'       => (int)$m['id'],
        'name'     => (string)$m['name'],
        'protocol' => (string)$m['protocol'],
        'model'    => (string)$m['model_key'],
        'credits'  => (int)$m['credits'],
        'owner'    => (int)($m['owner_id'] ?? 0) === 0 ? 'system' : 'self',
        'note'     => (string)($m['note'] ?? ''),
        'enabled'  => (int)$m['enabled'] === 1,
    ];
}

/** 拼 base 与路径，避免重复斜杠和重复后缀 */
function ai_join_url(string $base, string $path): string
{
    $base = rtrim(trim($base), '/');
    if ($base === '') {
        throw new AiError('bad_config', '模型地址（Base URL）没填');
    }
    if (!preg_match('#^https?://#i', $base)) {
        $base = 'https://' . $base;
    }
    if (str_ends_with(strtolower($base), strtolower($path))) {
        return $base;
    }
    return $base . $path;
}

/**
 * 把内部消息 [{role:system|user|assistant, content:string}] 转成各家请求体。
 * 返回 ['url','headers','body']。
 */
function ai_build_request(array $m, array $messages, array $opts = []): array
{
    $key = ai_model_secret($m);
    if ($key === '') {
        throw new AiError('bad_config', '这个模型还没配置 API Key');
    }
    $proto = ai_protocol((string)$m['protocol']);
    $model = (string)$m['model_key'];
    $maxTokens = (int)($opts['max_tokens'] ?? $m['max_tokens'] ?? 2048);
    $temp = (float)($opts['temperature'] ?? $m['temperature'] ?? 0.3);
    if ($maxTokens < 1) {
        $maxTokens = 1024;
    }
    $temp = max(0.0, min(2.0, $temp));

    $system = '';
    $turns = [];
    foreach ($messages as $msg) {
        $role = (string)($msg['role'] ?? 'user');
        $text = (string)($msg['content'] ?? '');
        if ($text === '') {
            continue;
        }
        if ($role === 'system') {
            $system = $system === '' ? $text : $system . "\n\n" . $text;
            continue;
        }
        $role = $role === 'assistant' ? 'assistant' : 'user';
        // 相邻同角色合并：有的上游对连续 user 会报错
        $last = count($turns) - 1;
        if ($last >= 0 && $turns[$last]['role'] === $role) {
            $turns[$last]['content'] .= "\n\n" . $text;
        } else {
            $turns[] = ['role' => $role, 'content' => $text];
        }
    }
    if (!$turns) {
        throw new AiError('bad_config', '没有可发送的内容');
    }

    if ($proto === 'anthropic') {
        $url = ai_join_url((string)$m['base_url'], '/v1/messages');
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temp,
            'messages' => array_map(fn($t) => ['role' => $t['role'], 'content' => $t['content']], $turns),
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }
        return [
            'url' => $url,
            'headers' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            'body' => $body,
        ];
    }

    if ($proto === 'gemini') {
        $base = rtrim(trim((string)$m['base_url']), '/');
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $url = $base . '/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        $contents = [];
        foreach ($turns as $t) {
            $contents[] = [
                'role' => $t['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $t['content']]],
            ];
        }
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => $temp],
        ];
        if ($system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }
        return ['url' => $url, 'headers' => [], 'body' => $body];
    }

    // openai 兼容
    $url = ai_join_url((string)$m['base_url'], '/chat/completions');
    $all = $system !== '' ? array_merge([['role' => 'system', 'content' => $system]], $turns) : $turns;
    return [
        'url' => $url,
        'headers' => ['Authorization: Bearer ' . $key],
        'body' => [
            'model' => $model,
            'messages' => $all,
            'max_tokens' => $maxTokens,
            'temperature' => $temp,
            'stream' => false,
        ],
    ];
}

/** URL 里的 key 打码，错误信息可以放心贴给别人看 */
function ai_mask_url(string $url): string
{
    return (string)preg_replace('/([?&](?:key|api_key|api-key|token|access_token)=)[^&#]*/i', '$1***', $url);
}

/** 拆 base_url：scheme / host / port / path（没写 scheme 时按 https 处理） */
function ai_url_parts(string $base): array
{
    $raw = trim($base);
    $url = preg_match('#^https?://#i', $raw) ? $raw : 'https://' . $raw;
    $u = @parse_url($url);
    if (!is_array($u)) {
        $u = [];
    }
    $scheme = strtolower((string)($u['scheme'] ?? 'https'));
    return [
        'scheme' => $scheme,
        'host'   => (string)($u['host'] ?? ''),
        'port'   => (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80)),
        'path'   => (string)($u['path'] ?? ''),
        'url'    => $url,
    ];
}

/**
 * 网络层失败 → [kind, 人话]。
 *
 * 关键是把「卡在哪一步」说清楚：域名没解析出来、TCP 都连不上、TLS 握手失败、
 * 还是连上了但上游一直不返数据。这四种情况处理方式完全不同，以前一律报「超时」。
 */
function ai_net_fail(string $url, int $errno, string $err, array $d, int $timeout): array
{
    $p = ai_url_parts($url);
    $host = $p['host'] !== '' ? $p['host'] : '(空)';
    $port = (int)$p['port'];
    $ip = (string)($d['ip'] ?? '');
    // curl 在连接阶段失败时拿不到 IP，这时候别写「未解析出 IP」，容易误导
    $where = $ip !== '' ? '（' . $ip . '）' : '';
    $conn = (int)($d['connect_ms'] ?? 0);
    $tls = (int)($d['tls_ms'] ?? 0);
    $total = (int)($d['total_ms'] ?? 0);
    $sec = $total > 0 ? round($total / 1000, 1) : 0;
    $raw = $err !== '' ? '（' . $err . '）' : '';
    $target = $host . ':' . $port;

    if ($errno === 6) {
        return ['network', '域名解析失败：解析不出 ' . $host . '。检查接口地址有没有拼错、域名是否过期' . $raw];
    }
    if ($errno === 7) {
        return ['network', '连不上服务器：' . $target . $where . ' TCP 没建立，' . $sec . 's 后放弃。'
            . '可能域名/IP 不通、端口没开，或这个接口只对特定网络开放' . $raw];
    }
    if ($errno === 35 || $errno === 53 || $errno === 54 || $errno === 58 || $errno === 59 || $errno === 64 || $errno === 66 || $errno === 77 || $errno === 80 || $errno === 83 || $errno === 90 || $errno === 91) {
        return ['network', 'TLS 握手失败：' . $target . $where . '。'
            . '常见原因是对方只支持特定 TLS 版本、证书链不完整，或中间设备在拦。' . $raw];
    }
    if ($errno === 60 || $errno === 51) {
        return ['network', 'HTTPS 证书校验失败：' . $target . $where . '。'
            . '如果对方是自签证书/中转站，需要换正规证书，或改用 http 地址' . $raw];
    }
    if ($errno === 28) {
        // conn=0 说明连 TCP 都没建起来（CURLOPT_CONNECTTIMEOUT 到点）；
        // conn>0 说明连上了却没等到数据，问题在上游而不是网络。
        if ($conn <= 0) {
            return ['timeout', '连接超时：' . $target . $where . round($total / 1000, 1) . 's 内 TCP 没连上。'
                . '这个地址现在不通，换网络或换服务商' . $raw];
        }
        return ['timeout', '已经连上 ' . $target . $where . '（TLS ' . $tls . 'ms），'
            . '但等了 ' . $timeout . 's 上游一个字节都没返回。'
            . '说明服务器是通的、是这个接口/模型在排队或挂住了：换模型、或把超时调小先让它快速失败' . $raw];
    }
    if ($errno === 52) {
        return ['network', '上游返回空响应：' . $target . $where . '。握手是通的，但对方直接关了连接'
            . '（常见于只认特定 UA、或需要鉴权先过网关）' . $raw];
    }
    if ($errno === 56) {
        return ['network', '连接被对端重置：' . $target . $where . '。可能被防火墙拦了，或对方拒绝这个来源 IP' . $raw];
    }
    return ['network', '连不上模型接口：' . $target . $where . ' curl #' . $errno
        . ($raw !== '' ? $raw : '，未知错误')];
}

/** 发请求；返回 ['code','body','ms','diag']，网络层失败抛 AiError */
function ai_http(string $url, array $headers, array $body, int $timeout): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new AiError('bad_config', '请求体编码失败');
    }
    $timeout = $timeout > 5 ? min($timeout, 300) : 120;

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'kb-portal/' . (defined('APP_VERSION') ? APP_VERSION : '1.8') . ' (+php)',
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ];
    if (defined('CURLOPT_PROTOCOLS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
    }
    curl_setopt_array($ch, $opts);

    $t0 = microtime(true);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $errno = (int)curl_errno($ch);
    $diag = [
        'ip'         => (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP),
        'connect_ms' => (int)round((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000),
        'tls_ms'     => (int)round((float)curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) * 1000),
        'ttfb_ms'    => (int)round((float)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000),
    ];
    curl_close($ch);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $diag['total_ms'] = $ms;

    if ($resp === false) {
        [$kind, $msg] = ai_net_fail($url, $errno, $err, $diag, $timeout);
        throw new AiError($kind, $msg);
    }
    return ['code' => $code, 'body' => (string)$resp, 'ms' => $ms, 'diag' => $diag];
}

/** 上游错误 → 人话 */
function ai_http_error(array $m, int $code, string $body): AiError
{
    $snippet = trim(preg_replace('/\s+/u', ' ', mb_substr($body, 0, 300)) ?? '');
    $msg = '';
    $d = json_decode($body, true);
    if (is_array($d)) {
        $msg = (string)($d['error']['message'] ?? $d['error']['msg'] ?? $d['message'] ?? $d['error'] ?? '');
    }
    $tail = $msg !== '' ? $msg : $snippet;

    if ($code === 401 || $code === 403) {
        return new AiError('auth', '模型密钥无效或没有权限（HTTP ' . $code . '）' . ($tail !== '' ? '：' . $tail : ''), $code);
    }
    if ($code === 429) {
        return new AiError('rate', '上游限流或额度用尽（HTTP 429）' . ($tail !== '' ? '：' . $tail : ''), $code);
    }
    if ($code === 404) {
        return new AiError('bad_config', '接口地址或模型名不对（HTTP 404）' . ($tail !== '' ? '：' . $tail : ''), $code);
    }
    if ($code >= 500) {
        return new AiError('network', '上游服务异常（HTTP ' . $code . '）' . ($tail !== '' ? '：' . $tail : ''), $code);
    }
    return new AiError('bad_response', '模型返回错误（HTTP ' . $code . '）' . ($tail !== '' ? '：' . $tail : ''), $code);
}

/** 解析响应正文 → ['text','usage'=>['in','out'],'model'] */
function ai_parse_response(array $m, string $body): array
{
    $d = json_decode($body, true);
    if (!is_array($d)) {
        throw new AiError('bad_response', '模型返回的不是 JSON：' . mb_substr($body, 0, 200));
    }
    $proto = ai_protocol((string)$m['protocol']);

    if ($proto === 'anthropic') {
        if (!empty($d['error'])) {
            throw new AiError('bad_response', '模型返回错误：' . (string)($d['error']['message'] ?? json_encode($d['error'])));
        }
        $text = '';
        foreach ((array)($d['content'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'text') {
                $text .= (string)($c['text'] ?? '');
            }
        }
        return [
            'text' => trim($text),
            'usage' => [
                'in'  => (int)($d['usage']['input_tokens'] ?? 0),
                'out' => (int)($d['usage']['output_tokens'] ?? 0),
            ],
            'model' => (string)($d['model'] ?? ''),
        ];
    }

    if ($proto === 'gemini') {
        if (!empty($d['error'])) {
            throw new AiError('bad_response', '模型返回错误：' . (string)($d['error']['message'] ?? ''));
        }
        $text = '';
        foreach ((array)($d['candidates'][0]['content']['parts'] ?? []) as $p) {
            $text .= (string)($p['text'] ?? '');
        }
        $blocked = (string)($d['candidates'][0]['finishReason'] ?? '');
        if (trim($text) === '' && $blocked !== '') {
            throw new AiError('bad_response', '模型没有输出内容（finishReason=' . $blocked . '）');
        }
        return [
            'text' => trim($text),
            'usage' => [
                'in'  => (int)($d['usageMetadata']['promptTokenCount'] ?? 0),
                'out' => (int)($d['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
            'model' => (string)($d['modelVersion'] ?? ($d['model'] ?? '')),
        ];
    }

    if (!empty($d['error'])) {
        $e = $d['error'];
        throw new AiError('bad_response', '模型返回错误：' . (is_array($e) ? (string)($e['message'] ?? json_encode($e)) : (string)$e));
    }
    $text = (string)($d['choices'][0]['message']['content'] ?? '');
    if (is_array($d['choices'][0]['message']['content'] ?? null)) {
        // 部分兼容实现把 content 拆成数组
        $buf = '';
        foreach ($d['choices'][0]['message']['content'] as $part) {
            $buf .= (string)($part['text'] ?? '');
        }
        $text = $buf;
    }
    if (trim($text) === '') {
        $fr = (string)($d['choices'][0]['finish_reason'] ?? '');
        if ($fr === 'length') {
            throw new AiError('bad_response', '输出被 max_tokens 截断了，把上限调大再试');
        }
        throw new AiError('bad_response', '模型返回了空内容');
    }
    return [
        'text' => trim($text),
        'usage' => [
            'in'  => (int)($d['usage']['prompt_tokens'] ?? 0),
            'out' => (int)($d['usage']['completion_tokens'] ?? 0),
        ],
        'model' => (string)($d['model'] ?? ''),
    ];
}

/**
 * 主入口：发一次对话。
 * 返回 ['text','usage'=>['in','out'],'model','latency_ms']
 */
function ai_chat(array $m, array $messages, array $opts = []): array
{
    $req = ai_build_request($m, $messages, $opts);
    $res = ai_http($req['url'], $req['headers'], $req['body'], (int)($m['timeout'] ?? 120));
    if ($res['code'] < 200 || $res['code'] >= 300) {
        throw ai_http_error($m, (int)$res['code'], (string)$res['body']);
    }
    $out = ai_parse_response($m, (string)$res['body']);
    $out['latency_ms'] = $res['ms'];
    return $out;
}

/** 后台「测试连接」：真发一次最小请求。
 *  超时压到最多 45 秒——测试是为了快速知道通不通，不是等一个慢回答。 */
function ai_probe(array $m): string
{
    $m['timeout'] = max(15, min(45, (int)($m['timeout'] ?? 120)));
    $out = ai_chat($m, [
        ['role' => 'user', 'content' => 'ping，请只回复 ok'],
    ], ['max_tokens' => 16, 'temperature' => 0]);
    $t = mb_substr($out['text'] !== '' ? $out['text'] : '(空)', 0, 40);
    return '通了：' . $m['name'] . ' 回复「' . $t . '」，耗时 ' . $out['latency_ms'] . ' ms'
        . ($out['usage']['in'] + $out['usage']['out'] > 0
            ? '，tokens ' . $out['usage']['in'] . '/' . $out['usage']['out']
            : '');
}

/** 从模型输出里抠 JSON（容忍 ```json 围栏与前后废话） */
function ai_extract_json(string $text): ?array
{
    $t = trim($text);
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $t, $m)) {
        $t = trim($m[1]);
    }
    $d = json_decode($t, true);
    if (is_array($d)) {
        return $d;
    }
    $start = strpos($t, '{');
    $end = strrpos($t, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $d = json_decode(substr($t, $start, $end - $start + 1), true);
        if (is_array($d)) {
            return $d;
        }
    }
    $start = strpos($t, '[');
    $end = strrpos($t, ']');
    if ($start !== false && $end !== false && $end > $start) {
        $d = json_decode(substr($t, $start, $end - $start + 1), true);
        if (is_array($d)) {
            return $d;
        }
    }
    return null;
}

/**
 * 给失败步骤一句能照做的建议。
 */
function ai_diag_advice(string $key, string $detail): string
{
    if ($key === 'url') {
        return '接口地址要写成「协议://域名/版本路径」，例如 https://api.deepseek.com/v1 。中转站地址请看它的文档，别自己补 /chat/completions。';
    }
    if ($key === 'dns') {
        return '域名解析不出来：先确认地址有没有打错（cloudflare / cloudforl 这种差一个字母就完全不通），再确认域名没过期。';
    }
    if ($key === 'tcp') {
        return '域名能解析但端口连不上：多半是这个 API 服务当前不可用、换了 IP，或者只对特定网络开放。先用浏览器打开它的官网确认还活着，再决定是否换服务商。';
    }
    if ($key === 'tls') {
        return 'TCP 通了但 TLS 握手失败：对方证书可能过期/不完整，或只支持特定 TLS 版本。换 https 正规地址；自签证书的私有服务建议前面加一层 nginx 反代。';
    }
    if ($key === 'http') {
        return '握手成功但 HTTP 层没给出正常响应：确认地址能打开、没被 WAF 拦。若返回 404/405 只是路径不对，改 Base URL 即可。';
    }
    return '网络是通的，问题在接口本身：看上面这行的返回内容，常见是模型名不存在、密钥无效、余额不足或上游排队。';
}

/**
 * 连通性体检：分段测，直接定位卡在 DNS / TCP / TLS / HTTP / 接口。
 *
 * @param bool $deep true 时真发一次最小对话（会消耗一次额度，用于确认模型名与密钥）
 * @return array{ok:bool,name:string,target:string,steps:array,advice:string}
 */
function ai_diagnose(array $m, bool $deep = false): array
{
    $p = ai_url_parts((string)($m['base_url'] ?? ''));
    $host = (string)$p['host'];
    $port = (int)$p['port'];
    $steps = [];

    $done = function () use (&$steps, $p, $m): array {
        $ok = true;
        $advice = '';
        foreach ($steps as $st) {
            if ($st['state'] === 'fail') {
                $ok = false;
                $advice = ai_diag_advice((string)$st['key'], (string)$st['detail']);
                break;
            }
        }
        return [
            'ok'     => $ok,
            'name'   => (string)($m['name'] ?? ''),
            'target' => ai_mask_url((string)$p['url']),
            'steps'  => $steps,
            'advice' => $advice,
        ];
    };
    $add = function (string $key, string $name, string $state, int $ms, string $detail) use (&$steps): void {
        $steps[] = ['key' => $key, 'name' => $name, 'state' => $state, 'ms' => $ms, 'detail' => $detail];
    };

    /* 1. 地址格式 */
    if ($host === '') {
        $add('url', '地址格式', 'fail', 0, '解析不出域名（原始值：「' . mb_substr((string)($m['base_url'] ?? ''), 0, 80) . '」）');
        return $done();
    }
    $add('url', '地址格式', 'ok', 0, $p['scheme'] . ' · ' . $host . ':' . $port . ($p['path'] !== '' ? $p['path'] : '/'));

    /* 2. DNS */
    $t = microtime(true);
    $ips = [];
    foreach ((array)@gethostbynamel($host) as $ipv4) {
        if (is_string($ipv4) && $ipv4 !== '') {
            $ips[] = $ipv4;
        }
    }
    foreach ((array)@dns_get_record($host, DNS_AAAA) as $rec) {
        if (!empty($rec['ipv6'])) {
            $ips[] = (string)$rec['ipv6'];
        }
    }
    $dnsMs = (int)round((microtime(true) - $t) * 1000);
    if (!$ips) {
        $add('dns', 'DNS 解析', 'fail', $dnsMs, '解析不出 ' . $host . ' 的任何 A/AAAA 记录');
        return $done();
    }
    $add('dns', 'DNS 解析', 'ok', $dnsMs, implode(' / ', array_slice($ips, 0, 4)) . (count($ips) > 4 ? ' 等 ' . count($ips) . ' 个' : ''));

    /* 3. TCP */
    $t = microtime(true);
    $hostForTcp = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $eno = 0;
    $estr = '';
    $fp = @fsockopen('tcp://' . $hostForTcp, $port, $eno, $estr, 6);
    $tcpMs = (int)round((microtime(true) - $t) * 1000);
    if (!$fp) {
        $why = trim($estr) !== '' ? trim($estr) : '6 秒内没连上';
        $add('tcp', 'TCP 连接', 'fail', $tcpMs, $host . ':' . $port . ' → ' . $why . '（errno ' . $eno . '）');
        return $done();
    }
    @fclose($fp);
    $add('tcp', 'TCP 连接', 'ok', $tcpMs, $host . ':' . $port . ' 端口可达');

    /* 4. TLS 握手 + HTTP 响应（HEAD，不消耗额度） */
    $ch = curl_init((string)$p['url']);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'kb-portal-diag/' . (defined('APP_VERSION') ? APP_VERSION : '2'),
    ]);
    curl_exec($ch);
    $cno = (int)curl_errno($ch);
    $cerr = (string)curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $viaIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
    $tlsMs = (int)round((float)curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) * 1000);
    $connMs = (int)round((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000);
    curl_close($ch);

    if ($p['scheme'] === 'https') {
        if ($tlsMs <= 0) {
            $add('tls', 'TLS 握手', 'fail', 0, '握手没完成' . ($cerr !== '' ? '：' . $cerr : '（curl #' . $cno . '）'));
            return $done();
        }
        $add('tls', 'TLS 握手', 'ok', $tlsMs, '握手成功 · 实际连到 ' . ($viaIp !== '' ? $viaIp : '?') . ' · TCP ' . $connMs . 'ms');
    }

    if ($cno !== 0 && $code === 0) {
        $add('http', 'HTTP 响应', 'fail', 0, '没拿到 HTTP 响应：' . ($cerr !== '' ? $cerr : 'curl #' . $cno));
        return $done();
    }
    $note = 'HTTP ' . $code;
    if ($code >= 400 && $code < 500) {
        $note .= '（4xx 不代表网络有问题，是这个路径不接受 HEAD；能返回状态码就说明服务在跑）';
    }
    $add('http', 'HTTP 响应', 'ok', 0, $note);

    /* 5. 接口调用（可选） */
    if (!$deep) {
        $add('api', '接口调用', 'skip', 0, '未执行。点「深度诊断」会真发一次最小对话，顺便验证模型名和密钥（消耗一次额度）');
        return $done();
    }
    $t = microtime(true);
    try {
        $probe = $m;
        $probe['timeout'] = max(15, min(45, (int)($m['timeout'] ?? 120)));
        $out = ai_chat($probe, [['role' => 'user', 'content' => 'ping，请只回复 ok']], ['max_tokens' => 16, 'temperature' => 0]);
        $add('api', '接口调用', 'ok', (int)round((microtime(true) - $t) * 1000),
            '模型回复「' . mb_substr((string)$out['text'], 0, 40) . '」，总耗时 ' . $out['latency_ms'] . 'ms');
    } catch (Throwable $e) {
        $add('api', '接口调用', 'fail', (int)round((microtime(true) - $t) * 1000), $e->getMessage());
    }
    return $done();
}
