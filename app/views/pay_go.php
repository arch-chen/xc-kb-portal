<?php
/** @var array $pay 发起结果 @var array|null $order 订单 */
$action = (string)($pay['action'] ?? '');
$params = (array)($pay['params'] ?? []);
$warning = (string)($pay['warning'] ?? '');
$route = 'recharge';
?>
<section class="page-head">
  <div>
    <h1 class="page-title">正在跳转到支付平台</h1>
    <p class="page-desc">请不要关闭本页。如果没有自动跳转，点下面的按钮继续。</p>
  </div>
</section>

<section class="panel">
  <?php if ($order): ?>
    <div class="stat-grid">
      <div class="stat">
        <div class="stat-n">¥<?= number_format((int)$order['amount_cents'] / 100, 2) ?></div>
        <div class="stat-l">应付金额</div>
      </div>
      <div class="stat">
        <div class="stat-n" style="font-size:15px"><?= e((string)($order['plan_name'] ?? '—')) ?></div>
        <div class="stat-l">套餐</div>
      </div>
      <div class="stat">
        <div class="stat-n" style="font-size:15px"><?= e(pay_method_name((string)($order['pay_method'] ?? ''))) ?></div>
        <div class="stat-l">支付方式</div>
      </div>
      <div class="stat">
        <div class="stat-n mono" style="font-size:13px"><?= e((string)$order['out_trade_no']) ?></div>
        <div class="stat-l">商户订单号</div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($warning !== ''): ?>
    <div class="hint" style="margin-top:14px"><p><?= e($warning) ?></p></div>
  <?php endif; ?>

  <?php if ($action !== ''): ?>
    <form id="payForm" method="post" action="<?= e($action) ?>" style="margin-top:16px">
      <?php foreach ($params as $k => $v): ?>
        <input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>">
      <?php endforeach; ?>
      <button class="btn primary" type="submit">继续去支付</button>
      <a class="btn" href="<?= e(u('recharge')) ?>">取消，返回充值页</a>
    </form>
    <script>document.getElementById('payForm').submit();</script>
  <?php else: ?>
    <p class="muted" style="margin-top:14px">支付平台没有返回可用的跳转地址。请返回充值页重试，或联系管理员。</p>
    <a class="btn" href="<?= e(u('recharge')) ?>">返回充值页</a>
  <?php endif; ?>

  <div class="hint" style="margin-top:16px">
    <p><b>付款完成后</b>会自动回到本站。如果页面没跳回来，也能在「账户设置 → 账单 → 我的订单」里看到状态；
       积分通常几秒内到账，超过 5 分钟还没到账请点订单旁的「刷新状态」。</p>
  </div>
</section>
