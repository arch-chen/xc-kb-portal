<?php
/**
 * 支付回跳落地页（免登录）
 * 只展示「查到的事实」：订单状态与金额。真正发货在 notify / 主动查单里完成。
 * @var bool $verified @var string $error @var array|null $order @var string $state @var string $note @var bool $logged
 */
$u = current_user();
$paid = $order && (string)$order['status'] === 'paid';
$refunded = $order && (string)$order['status'] === 'refunded';
$pending = !$paid && !$refunded && ($state === 'pending' || $state === '');

if ($paid) {
    $head = '支付成功';
    $tone = 'var(--ok, #17a673)';
    $desc = '积分 / 套餐已到账，可以直接去用了。';
} elseif ($refunded) {
    $head = '订单已退款';
    $tone = 'var(--muted, #888)';
    $desc = '这笔订单已经退款到原支付账户。';
} elseif ($pending) {
    $head = '支付结果确认中';
    $tone = 'var(--warn, #d08700)';
    $desc = '平台还没确认收款。如果你已经付过款，稍等几十秒刷新「我的订单」即可；超过 5 分钟仍显示待支付，请把订单号发给管理员。';
} else {
    $head = '未能确认支付';
    $tone = 'var(--err, #d64545)';
    $desc = '没有查到有效的支付结果。可能是页面被手动关闭，或者支付没有完成。';
}
$route = 'recharge';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($head) ?> · <?= e(app_name()) ?></title>
<?= site_favicon_tag() ?>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function () {
  try {
    var t = localStorage.getItem('kb-theme') || 'system';
    document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
<style>
  .pr-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 18px; }
  .pr-card { width: 100%; max-width: 520px; border: 1px solid var(--border, #e3e6ea); border-radius: 14px; background: var(--panel, #fff); padding: 28px 26px; }
  .pr-kicker { font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted, #8b94a0); }
  .pr-head { font-size: 24px; margin: 8px 0 10px; }
  .pr-desc { color: var(--text-2, #4a5260); line-height: 1.75; font-size: 14px; margin: 0 0 18px; }
  .pr-rows { border-top: 1px solid var(--border, #e3e6ea); padding-top: 14px; display: grid; gap: 10px; margin-bottom: 20px; }
  .pr-row { display: flex; gap: 12px; justify-content: space-between; font-size: 13px; }
  .pr-row span:first-child { color: var(--muted, #8b94a0); flex: 0 0 auto; }
  .pr-row span:last-child { text-align: right; word-break: break-all; }
  .pr-acts { display: flex; flex-wrap: wrap; gap: 10px; }
  .pr-note { margin-top: 16px; font-size: 12px; color: var(--muted, #8b94a0); line-height: 1.7; }
</style>
</head>
<body>
<?= github_corner_link('fixed') ?>
<div class="pr-wrap">
  <div class="pr-card">
    <div class="pr-kicker"><?= e(app_name()) ?></div>
    <h1 class="pr-head" style="color:<?= $tone ?>"><?= e($head) ?></h1>
    <p class="pr-desc"><?= e($desc) ?></p>

    <?php if ($order): ?>
      <div class="pr-rows">
        <div class="pr-row"><span>套餐</span><span><?= e((string)($order['plan_name'] ?? '—')) ?></span></div>
        <div class="pr-row"><span>金额</span><span>¥<?= number_format((int)$order['amount_cents'] / 100, 2) ?></span></div>
        <div class="pr-row"><span>商户订单号</span><span class="mono"><?= e((string)$order['out_trade_no']) ?></span></div>
        <?php if (!empty($order['pay_method'])): ?>
          <div class="pr-row"><span>支付方式</span><span><?= e(pay_method_name((string)$order['pay_method'])) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['transaction_id'])): ?>
          <div class="pr-row"><span>平台流水号</span><span class="mono"><?= e((string)$order['transaction_id']) ?></span></div>
        <?php endif; ?>
        <div class="pr-row">
          <span>状态</span>
          <span><?= e(['pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭', 'refunded' => '已退款'][(string)$order['status']] ?? (string)$order['status']) ?></span>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($note !== '' && $pending): ?>
      <div class="pr-note">平台返回：<?= e($note) ?></div>
    <?php endif; ?>
    <?php if (!$verified && $error !== ''): ?>
      <div class="pr-note">校验信息：<?= e($error) ?>（这不代表没付款，以订单状态为准）</div>
    <?php endif; ?>

    <div class="pr-acts">
      <?php if ($u): ?>
        <a class="btn primary" href="<?= e(u('recharge')) ?>">查看我的订单</a>
        <a class="btn" href="<?= e(u('ai')) ?>">去 AI 助手</a>
      <?php else: ?>
        <a class="btn primary" href="<?= e(u('login')) ?>">登录后查看订单</a>
        <a class="btn" href="<?= e(u()) ?>">返回首页</a>
      <?php endif; ?>
    </div>

    <div class="pr-note">
      积分通常几秒内到账。若「我的订单」仍显示待支付，点该订单的「刷新状态」会主动向支付平台查一次。
    </div>

  </div>
</div>
</body>
</html>
