<?php
declare(strict_types=1);

/**
 * 支付渠道抽象层（1.9.0）
 *
 * 设计口径：站内其它代码只跟 PayGateway 打交道，不直接拼某个平台的参数。
 * 以后要接微信支付/支付宝官方，只要再实现一个 PayGateway，加进 pay_gateway() 的 switch，
 * 订单表、发货逻辑、后台页面都不用动。
 *
 * 目前实现：彩虹易支付（Epay）V1 接口（MD5 签名，submit.php / mapi.php / api.php）。
 */

class PayError extends RuntimeException
{
    public string $kind;

    public function __construct(string $kind, string $message)
    {
        parent::__construct($message);
        $this->kind = $kind;
    }
}

interface PayGateway
{
    /** 渠道代码，落库用 */
    public function code(): string;

    /** 渠道显示名 */
    public function label(): string;

    /**
     * 发起支付。
     * 返回：
     *   ['ok'=>true,'mode'=>'jump','url'=>'https://...']        直接 302 过去
     *   ['ok'=>true,'mode'=>'form','action'=>'...','params'=>[]] 渲染自动提交表单（平台收银台）
     *   ['ok'=>true,'mode'=>'qrcode','qrcode'=>'weixin://...']   只有二维码内容，交给页面提示
     *   ['ok'=>false,'error'=>'人话原因']
     */
    public function start(array $order, string $method, string $clientIp, string $device): array;

    /**
     * 校验异步通知/同步跳转参数。
     * 返回 ['ok'=>bool,'error'=>'','paid'=>bool,'out_trade_no'=>'','trade_no'=>'','money'=>'1.00','type'=>'wxpay']
     * 注意：只做「签名 + 商户号 + 字段完整性」，金额与订单状态核对留给业务层。
     */
    public function verify(array $params): array;

    /** 主动查单（用于通知丢失时补单） */
    public function query(string $outTradeNo, string $tradeNo = ''): array;

    /** 退款 */
    public function refund(string $outTradeNo, string $money, string $tradeNo = ''): array;

    /** 连通性自检 */
    public function probe(): array;
}

/** 按配置拿到渠道实例 */
function pay_gateway(array $conf): PayGateway
{
    $code = (string)($conf['gateway'] ?? 'epay');
    if ($code === 'epay') {
        return new EpayGateway($conf);
    }
    throw new PayError('bad_gateway', '未知的支付渠道：' . $code);
}

/** 已支持的渠道（后台下拉用） */
function pay_gateway_list(): array
{
    return ['epay' => '彩虹易支付（V1 接口）'];
}

/* ---------------- 公共小工具 ---------------- */

/** 分 → 元字符串，两位小数（易支付只收字符串金额，别给浮点数） */
function pay_money(int $cents): string
{
    return number_format(max(0, $cents) / 100, 2, '.', '');
}

/** 元（字符串）→ 分。用于核对回调金额，避免 "1.0" 与 "1.00" 直接字符串比较 */
function pay_cents(string $money): int
{
    $m = trim($money);
    if ($m === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $m)) {
        return -1;
    }
    return (int)round((float)$m * 100);
}

/** 由 UA 判断设备类型（易支付 device 参数） */
function pay_device(string $ua): string
{
    $ua = strtolower($ua);
    if ($ua === '') {
        return 'pc';
    }
    if (str_contains($ua, 'micromessenger')) {
        return 'wechat';
    }
    if (str_contains($ua, 'alipayclient')) {
        return 'alipay';
    }
    if (str_contains($ua, 'qq/') || str_contains($ua, 'qq浏览器')) {
        return 'qq';
    }
    if (preg_match('/android|iphone|ipad|ipod|harmonyos|mobile/', $ua)) {
        return 'mobile';
    }
    return 'pc';
}
