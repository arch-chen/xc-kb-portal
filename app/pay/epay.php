<?php
declare(strict_types=1);

/**
 * 彩虹易支付（Epay）V1 接口驱动
 *
 * 文档：https://pay.cfxcc.cn/doc/v1_legacy_api.html
 *   - 页面跳转支付  POST {api}/submit.php
 *   - API 接口支付  POST {api}/mapi.php   → 返回 payurl / qrcode / urlscheme 之一
 *   - 回调通知      GET  notify_url / return_url
 *   - 查单/退款/商户信息 GET|POST {api}/api.php?act=order|refund|query
 *
 * 签名（MD5）：参数名 ASCII 升序 → 拼 a=b&c=d（值不做 URL 编码）→ + 商户密钥 → md5 小写，
 * sign 与 sign_type、空值不参与签名。
 */
class EpayGateway implements PayGateway
{
    private array $c;

    public function __construct(array $conf)
    {
        $this->c = $conf;
    }

    public function code(): string
    {
        return 'epay';
    }

    public function label(): string
    {
        return '彩虹易支付';
    }

    /* ---------------- 签名 ---------------- */

    /**
     * 易支付 MD5 签名。
     * 关键点：
     *  1. sign / sign_type 不参与；空值不参与（但 "0" 要保留，"0" 在 PHP 里是假值，不能用 empty 过滤）
     *  2. 值不做 URL 编码，直接 k=v 拼接
     */
    public static function sign(array $p, string $key): string
    {
        unset($p['sign'], $p['sign_type']);
        $keep = [];
        foreach ($p as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $keep[(string)$k] = (string)$v;
        }
        ksort($keep, SORT_STRING);
        $parts = [];
        foreach ($keep as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return md5(implode('&', $parts) . $key);
    }

    /** 校验一组参数的签名 */
    public function verifySign(array $params): bool
    {
        $sign = (string)($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        $type = strtoupper((string)($params['sign_type'] ?? 'MD5'));
        if ($type !== 'MD5') {
            return false; // V2 的 RSA 签名本期不支持，明确拒绝而不是放过
        }
        return hash_equals(self::sign($params, (string)$this->c['key']), strtolower($sign));
    }

    /* ---------------- 发起支付 ---------------- */

    public function start(array $order, string $method, string $clientIp, string $device): array
    {
        $name = trim((string)($this->c['title_prefix'] ?? '')) . (string)$order['plan_name'];
        if ($name === '') {
            $name = '知识库充值';
        }
        $base = [
            'pid'          => (string)$this->c['pid'],
            'type'         => $method,
            'out_trade_no' => (string)$order['out_trade_no'],
            'notify_url'   => (string)$this->c['notify_url'],
            'return_url'   => (string)$this->c['return_url'],
            'name'         => mb_substr($name, 0, 60),
            'money'        => pay_money((int)$order['amount_cents']),
            'param'        => 'kb-' . (int)$order['id'],
        ];

        $fallback = $this->submitForm($base, $method);

        if (empty($this->c['use_mapi'])) {
            return $fallback;
        }

        $params = $base + [
            'clientip' => $clientIp !== '' ? $clientIp : '127.0.0.1',
            'device'   => $device !== '' ? $device : 'pc',
        ];
        $params['sign'] = self::sign($params, (string)$this->c['key']);
        $params['sign_type'] = 'MD5';

        $r = $this->http($this->url('mapi.php'), $params);
        if (!$r['ok']) {
            return $fallback + ['warning' => '接口支付通道不可用，已改用收银台：' . $r['error']];
        }
        $j = $r['json'];
        if ((int)($j['code'] ?? 0) !== 1) {
            // 平台明确拒绝（通道未开通、金额超限等），仍然退回收银台再试一次
            return $fallback + ['warning' => '接口支付未受理（' . (string)($j['msg'] ?? '未知原因') . '），已改用收银台'];
        }

        $payurl = trim((string)($j['payurl'] ?? ''));
        $qrcode = trim((string)($j['qrcode'] ?? ''));
        $scheme = trim((string)($j['urlscheme'] ?? ''));
        $tradeNo = trim((string)($j['trade_no'] ?? ''));

        if ($payurl !== '') {
            return ['ok' => true, 'mode' => 'jump', 'url' => $payurl, 'trade_no' => $tradeNo, 'gateway' => 'epay'];
        }
        if ($scheme !== '') {
            return ['ok' => true, 'mode' => 'jump', 'url' => $scheme, 'trade_no' => $tradeNo, 'gateway' => 'epay'];
        }
        if ($qrcode !== '') {
            // 只有二维码内容（多为 native 扫码）：交给收银台页面，平台自己会渲染二维码
            return $fallback + ['qrcode' => $qrcode, 'trade_no' => $tradeNo];
        }
        return $fallback;
    }

    /** 收银台表单（submit.php）——任何情况下都能用的兜底 */
    private function submitForm(array $base, string $method): array
    {
        $params = $base;
        if ($method !== '') {
            $params['type'] = $method;
        }
        $params['sign'] = self::sign($params, (string)$this->c['key']);
        $params['sign_type'] = 'MD5';
        return [
            'ok'     => true,
            'mode'   => 'form',
            'action' => $this->url('submit.php'),
            'params' => $params,
            'gateway' => 'epay',
        ];
    }

    /* ---------------- 回调校验 ---------------- */

    public function verify(array $params): array
    {
        $out = [
            'ok' => false, 'error' => '', 'paid' => false,
            'out_trade_no' => '', 'trade_no' => '', 'money' => '', 'type' => '', 'status' => '',
        ];
        // 注意：不能用 $out + ['error'=>...]，数组并集会保留左侧已有的空 error 键
        $pid = (string)($params['pid'] ?? '');
        if ($pid === '' || $pid !== (string)$this->c['pid']) {
            $out['error'] = '商户号不匹配（本站 ' . (string)$this->c['pid'] . '，通知 ' . $pid . '）';
            return $out;
        }
        $outTradeNo = trim((string)($params['out_trade_no'] ?? ''));
        if ($outTradeNo === '') {
            $out['error'] = '缺少商户订单号';
            return $out;
        }
        if (!$this->verifySign($params)) {
            $out['error'] = '签名校验失败（检查商户密钥是否与平台一致）';
            return $out;
        }
        $status = (string)($params['trade_status'] ?? '');
        $out['ok'] = true;
        $out['paid'] = $status === 'TRADE_SUCCESS';
        $out['out_trade_no'] = $outTradeNo;
        $out['trade_no'] = trim((string)($params['trade_no'] ?? ''));
        $out['money'] = trim((string)($params['money'] ?? ''));
        $out['type'] = trim((string)($params['type'] ?? ''));
        $out['status'] = $status;
        return $out;
    }

    /* ---------------- 查单 / 退款 / 自检 ---------------- */

    public function query(string $outTradeNo, string $tradeNo = ''): array
    {
        $q = [
            'act' => 'order',
            'pid' => (string)$this->c['pid'],
            'key' => (string)$this->c['key'],
        ];
        if ($tradeNo !== '') {
            $q['trade_no'] = $tradeNo;
        } else {
            $q['out_trade_no'] = $outTradeNo;
        }
        $r = $this->http($this->url('api.php') . '?' . http_build_query($q));
        if (!$r['ok']) {
            return ['ok' => false, 'paid' => false, 'error' => $r['error']];
        }
        $j = $r['json'];
        if ((int)($j['code'] ?? 0) !== 1) {
            return ['ok' => false, 'paid' => false, 'error' => (string)($j['msg'] ?? '查单失败')];
        }
        return [
            'ok'      => true,
            'paid'    => (int)($j['status'] ?? 0) === 1,
            'trade_no' => (string)($j['trade_no'] ?? ''),
            'money'   => (string)($j['money'] ?? ''),
            'type'    => (string)($j['type'] ?? ''),
            'status'  => (int)($j['status'] ?? 0),
        ];
    }

    public function refund(string $outTradeNo, string $money, string $tradeNo = ''): array
    {
        $p = [
            'pid'   => (string)$this->c['pid'],
            'key'   => (string)$this->c['key'],
            'money' => $money,
        ];
        if ($tradeNo !== '') {
            $p['trade_no'] = $tradeNo;
        } else {
            $p['out_trade_no'] = $outTradeNo;
        }
        $r = $this->http($this->url('api.php') . '?act=refund', $p);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        $j = $r['json'];
        $msg = trim((string)($j['msg'] ?? ''));
        // 平台侧说「已退款 / 重复退款」= 钱已经退了，当成功处理，免得管理员反复点
        if ($msg !== '' && preg_match('/已退款|重复退款|退款完成/u', $msg)) {
            return ['ok' => true, 'message' => $msg, 'already' => true];
        }
        // 注意：退款接口的 code=0 表示成功，与其它接口的 1 相反
        if ((int)($j['code'] ?? -1) !== 0) {
            return ['ok' => false, 'error' => epay_refund_hint($msg !== '' ? $msg : '退款失败'), 'raw' => $msg];
        }
        return ['ok' => true, 'message' => $msg !== '' ? $msg : '退款成功'];
    }

    public function probe(): array
    {
        $q = ['act' => 'query', 'pid' => (string)$this->c['pid'], 'key' => (string)$this->c['key']];
        $r = $this->http($this->url('api.php') . '?' . http_build_query($q));
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['error'], 'refund' => null, 'key_ok' => null];
        }
        $j = $r['json'];
        if ((int)($j['code'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => '商户号或密钥不对：' . (string)($j['msg'] ?? '平台拒绝'), 'refund' => null, 'key_ok' => null];
        }
        $active = (int)($j['active'] ?? 0) === 1;

        // 平台会把商户密钥原样回显一次——正好用来确认本地存的是完整值（不是掩码、没被截断）
        $echoKey = (string)($j['key'] ?? '');
        $keyOk = $echoKey === '' ? null : hash_equals((string)$this->c['key'], $echoKey);

        // 部分版本在商户信息里带回退款开关，能读到就直接显示，省得去后台翻
        $refund = array_key_exists('refund', $j) ? (int)$j['refund'] : null;

        $msg = ($active ? '连接正常' : '商户已被封禁')
            . '，余额 ¥' . (string)($j['money'] ?? '0.00')
            . '，今日订单 ' . (int)($j['order_today'] ?? 0) . ' 笔';
        if ($keyOk === false) {
            $msg .= '；⚠ 平台回显的密钥与本地保存的不一致，查单和退款会全部失败，请重新粘贴一次完整密钥';
        }
        if ($refund === 1) {
            $msg .= '；退款 API：已开启';
        } elseif ($refund === 0) {
            $msg .= '；退款 API：未开启（要用商户号登录支付平台「商户后台」打开「订单退款 API」）';
        }

        return [
            'ok'      => $active && $keyOk !== false,
            'message' => $msg,
            'refund'  => $refund,
            'key_ok'  => $keyOk,
        ];
    }

    /**
     * 退款接口自检：拿一个肯定不存在的订单号调一次退款接口，只看平台怎么回。
     *
     * 为什么需要：真实退款失败时平台只回一句「商户未开启订单退款API接口」，
     * 但很多人明明确认自己开过——因为「开启商户自助退款」和「订单退款 API」是两个开关，
     * 前者只让你在商户后台点按钮退，后者才允许程序调用。这里用不存在的单号探一次：
     *   回「未开启退款」 → 权限确实没开；
     *   回「订单不存在」 → 权限这关已经过了，之前的失败是别的原因（单号/金额/通道）。
     * 单号不存在，所以不会产生任何真实退款。
     */
    public function refundSelftest(): array
    {
        $no = 'KBSELF' . date('YmdHis') . mt_rand(100, 999);
        $p = [
            'pid'          => (string)$this->c['pid'],
            'key'          => (string)$this->c['key'],
            'money'        => '0.01',
            'out_trade_no' => $no,
        ];
        $r = $this->http($this->url('api.php') . '?act=refund', $p);
        if (!$r['ok']) {
            return ['ok' => false, 'state' => 'net', 'msg' => '连不上退款接口：' . $r['error'], 'probe_no' => $no];
        }
        $j = $r['json'];
        $code = (int)($j['code'] ?? -1);
        $msg = trim((string)($j['msg'] ?? ''));
        if (preg_match('/未开启|未开通|没有开启|not\s*open|disabled/iu', $msg)) {
            return ['ok' => false, 'state' => 'denied', 'msg' => '平台仍然拒绝：' . $msg, 'probe_no' => $no];
        }
        if (preg_match('/不存在|not\s*found|无此订单|订单号/u', $msg)) {
            return ['ok' => true, 'state' => 'allowed', 'msg' => '退款接口已放行（平台回「' . $msg . '」）', 'probe_no' => $no];
        }
        return [
            'ok'    => true,
            'state' => 'other',
            'msg'   => '平台回复：' . ($msg !== '' ? $msg : 'code=' . $code),
            'probe_no' => $no,
        ];
    }

    /* ---------------- 底层 ---------------- */

    private function url(string $file): string
    {
        return rtrim((string)$this->c['api'], '/') . '/' . ltrim($file, '/');
    }

    /**
     * @param array|null $post null 表示 GET
     */
    private function http(string $url, ?array $post = null): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '服务器没装 curl 扩展，无法对接支付平台'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'kb-portal/' . (defined('APP_VERSION') ? APP_VERSION : '1.9.0'),
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => '连不上支付平台：' . $err];
        }
        $j = json_decode((string)$body, true);
        if (!is_array($j)) {
            $head = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$body)) ?? ''), 0, 120);
            return ['ok' => false, 'error' => '支付平台返回异常（HTTP ' . $code . '）：' . $head];
        }
        return ['ok' => true, 'json' => $j];
    }
}

/**
 * 把支付平台的退款报错翻译成人话。
 * 最常见的是「商户未开启退款API接口」——这是平台给商户号开的权限，本地代码改不了，
 * 必须去易支付后台开（或找客服），所以提示里要直接告诉他去哪儿开，并给出兜底办法。
 */
function epay_refund_hint(string $msg): string
{
    $m = trim($msg);
    if ($m === '') {
        return '退款失败（平台没给原因）';
    }
    if (preg_match('/未开启|未开通|没有开启|not\s*open|disabled/iu', $m) && preg_match('/退款|refund/iu', $m)) {
        return '支付平台没有给这个商户号开「订单退款 API」（平台原话：' . $m . '）。'
            . '注意它和「开启商户自助退款」不是同一个开关：后者只允许你在商户后台点按钮退款，前者才允许程序调用接口。'
            . '开法：用商户号登录支付平台的「商户后台」（不是平台管理员后台），在「个人资料 / API 信息」或「商户设置」里打开「订单退款 API」并保存；'
            . '找不到就让客服开，原话发给他即可。'
            . '想先确认到底卡在哪，回「支付设置」点一次「退款接口自检」。'
            . '开通前可以先在平台后台手工退款，再回本站订单列表点「查单 / 同步」（会自动识别平台已退款）或「线下已退」。';
    }
    if (preg_match('/权限|permission|forbidden|denied/iu', $m)) {
        return '支付平台拒绝了这次退款（平台原话：' . $m . '），通常是商户号没有退款权限或密钥不对。'
            . '先到易支付 / 支付设置里点「测试连接」看密钥是否正常，仍不行就联系平台客服。';
    }
    if (preg_match('/余额|balance|不足/iu', $m)) {
        return '支付平台商户余额不足，退不了（平台原话：' . $m . '）。先给商户号充值再退，或线下退给用户后点「线下已退」。';
    }
    if (preg_match('/不存在|not\s*found|无此订单|订单号/iu', $m)) {
        return '支付平台查不到这笔订单（平台原话：' . $m . '）。确认订单号与商户号是否对应；'
            . '如果钱确实已经退了，可直接点「线下已退」标记。';
    }
    if (preg_match('/不支持|不支持该|not\s*support|渠道/iu', $m)) {
        return '这个支付渠道不支持原路退款（平台原话：' . $m . '）。只能线下退给用户，再点「线下已退」标记。';
    }
    return '退款失败（平台原话：' . $m . '）';
}
