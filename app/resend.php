<?php
declare(strict_types=1);

/**
 * Resend 邮件通道（HTTPS API，一个 Key 即可发信）。
 *
 * 为什么单独做一个通道，而不是走 SMTP：
 *   - 云主机普遍封外发 25/465/587，SMTP 经常连不上，Resend 走 443 不受影响；
 *   - 配置只需要三样：API Key、发件人邮箱、发件人名称，没有服务器/端口/加密方式；
 *   - 投递结果有明确的结构化错误（域名没验证、额度用完等），能直接翻成人话。
 *
 * 接口：POST https://api.resend.com/emails
 *   Header: Authorization: Bearer re_xxx / Content-Type: application/json
 *   Body:   {"from":"Name <a@b.com>","to":["x@y.com"],"subject":"…","html":"…","text":"…"}
 *   成功:   {"id":"49a3999c-…"}
 *   失败:   {"statusCode":403,"name":"validation_error","message":"…"}
 *
 * 与 smtp_send() 一致的契约：不抛异常，一定返回 ['ok'=>bool,'error'=>string,'log'=>array]。
 */

const RESEND_ENDPOINT = 'https://api.resend.com/emails';

/**
 * 把 Resend 的英文报错翻成能直接照做的中文。
 * 只翻译「知道该怎么办」的那几类，其余原样带出，免得把真实原因藏起来。
 */
function resend_error_hint(int $code, string $name, string $message): string
{
    $m = $message;

    // 403：域名没验证 / 只能用 resend.dev 给自己发 —— 新手最常踩的两个
    if ($code === 403) {
        if (stripos($m, 'You can only send testing emails to your own email address') !== false) {
            return '当前用的是测试发件域名（resend.dev），只能发给 Resend 账号本人的邮箱。'
                . '要给其他人发信，请先在 resend.com/domains 添加并验证你自己的域名，'
                . '再把「发件人邮箱」改成该域名下的地址（例如 noreply@你的域名.com）。'
                . '原始报错：' . $m;
        }
        if (stripos($m, 'is not verified') !== false || stripos($m, 'domain is not verified') !== false) {
            return '发件人邮箱所在的域名还没在 Resend 验证通过。'
                . '到 resend.com/domains 添加该域名，按提示配好 DNS 记录（SPF/DKIM），'
                . '状态变成 Verified 后再发。原始报错：' . $m;
        }
        if (stripos($m, 'not active') !== false || stripos($m, 'suspended') !== false) {
            return '这把 API Key 已失效或被停用，请到 resend.com/api-keys 重新生成一把。原始报错：' . $m;
        }
        return 'Resend 拒绝了本次请求（403）：' . $m;
    }

    // 401：Key 不对 / 没带
    if ($code === 401) {
        if (stripos($m, 'Missing API Key') !== false) {
            return '没有带上 API Key，请检查后台「Resend API Key」是否已填写并保存。';
        }
        return 'API Key 无效（401）。请到 resend.com/api-keys 复制完整 Key（以 re_ 开头），'
            . '注意不要多复制空格或换行。原始报错：' . $m;
    }

    // 422：字段问题
    if ($code === 422) {
        if (stripos($m, 'missing') !== false) {
            return '请求缺少必填字段（422）：' . $m;
        }
        return '字段校验没通过（422）：' . $m;
    }

    // 429：额度 / 频率
    if ($code === 429) {
        if (stripos($m, 'daily') !== false) {
            return '今天的发信额度已用完（免费版每天 100 封），明天 UTC 0 点重置，或升级套餐。';
        }
        if (stripos($m, 'monthly') !== false) {
            return '本月发信额度已用完，请升级套餐或等到下月重置。';
        }
        return '请求太快被限流（429），稍等几秒再试。原始报错：' . $m;
    }

    if ($code >= 500) {
        return 'Resend 服务端暂时故障（' . $code . '），稍后重试；持续失败可看 resend-status.com。';
    }

    return 'Resend 返回 ' . $code . ($name !== '' ? '（' . $name . '）' : '') . '：' . $m;
}

/**
 * 走 Resend 发一封信。
 *
 * @param array $c mail_cfg() 的返回；用到 api_key / from / from_name
 * @return array{ok:bool,error:string,log:array}
 */
function resend_send(array $c, string $to, string $subject, string $html, string $text = ''): array
{
    $key = trim((string)($c['api_key'] ?? ''));
    $from = trim((string)($c['from'] ?? ''));
    $fromName = str_replace(["\r", "\n"], '', (string)($c['from_name'] ?? ''));

    if ($key === '') {
        return ['ok' => false, 'error' => '还没有填写 Resend API Key', 'log' => []];
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '「发件人邮箱」不是合法地址', 'log' => []];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '服务器没装 curl 扩展，无法调用 Resend API', 'log' => []];
    }
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '收件地址不合法：' . $to, 'log' => []];
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    // Resend 的 from 支持 "显示名 <地址>"，显示名里有中文也没问题（JSON 走 UTF-8）
    $fromField = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;

    $payload = [
        'from'    => $fromField,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($text !== '') {
        $payload['text'] = $text;
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return ['ok' => false, 'error' => '邮件内容编码失败：' . json_last_error_msg(), 'log' => []];
    }

    $ch = curl_init(RESEND_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_USERAGENT      => 'kb-portal/' . (defined('APP_VERSION') ? APP_VERSION : '?') . ' (+php)',
    ]);
    if (defined('CURLOPT_PROTOCOLS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        $hint = '网络层失败：' . ($err !== '' ? $err : '未知错误');
        if ($errno === 6) {
            $hint = '域名解析失败，服务器可能连不上外网或 DNS 有问题';
        } elseif ($errno === 7) {
            $hint = '连不上 api.resend.com:443，可能是服务器出网被防火墙拦了';
        } elseif ($errno === 28) {
            $hint = '连接 Resend 超时（30 秒），稍后重试';
        } elseif ($errno === 35 || $errno === 60) {
            $hint = 'TLS 握手失败，服务器 CA 根证书可能缺失（PHP 的 curl.cainfo / openssl.cafile）';
        }
        return ['ok' => false, 'error' => $hint . ($err !== '' ? '（' . $err . '）' : ''), 'log' => []];
    }

    $json = json_decode((string)$raw, true);
    $log = ['POST ' . RESEND_ENDPOINT . ' → ' . $code];

    if ($code >= 200 && $code < 300) {
        $id = is_array($json) ? (string)($json['id'] ?? '') : '';
        $log[] = 'id=' . $id;
        return ['ok' => true, 'error' => '', 'log' => $log];
    }

    // 失败：Resend 的错误体是 {statusCode,name,message}
    $name = '';
    $msg = '';
    if (is_array($json)) {
        $name = (string)($json['name'] ?? '');
        $msg = (string)($json['message'] ?? '');
        // 有的错误把细节放在 error 里
        if ($msg === '' && isset($json['error']['message'])) {
            $msg = (string)$json['error']['message'];
        }
    }
    if ($msg === '') {
        $msg = mb_substr(trim((string)$raw), 0, 300);
    }

    return ['ok' => false, 'error' => resend_error_hint($code, $name, $msg), 'log' => $log];
}
