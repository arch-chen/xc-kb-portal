<?php
/** @var array $ent 权益 @var array $wallet 钱包 @var array $plans 套餐 @var array $orders 订单 @var array $logs 流水 */
$route = 'recharge';
?>
<section class="page-head">
  <div>
    <h1 class="page-title">账单</h1>
    <p class="page-desc">积分按次扣：模型越强，单次积分越高。月付套餐额外解锁「自带模型」和更高每日次数。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('ai')) ?>">AI 助手</a>
    <a class="btn" href="<?= e(u('ai_models')) ?>">我的模型</a>
  </div>
</section>

<?= nav_bill_tabs('recharge') ?>

<section class="panel">
  <div class="stat-grid">
    <div class="stat">
      <div class="stat-n"><?= (int)$wallet['credits'] ?></div>
      <div class="stat-l">当前积分</div>
    </div>
    <div class="stat">
      <div class="stat-n"><?= (int)$wallet['total_in'] ?></div>
      <div class="stat-l">累计入账</div>
    </div>
    <div class="stat">
      <div class="stat-n"><?= (int)$wallet['total_out'] ?></div>
      <div class="stat-l">累计消耗</div>
    </div>
    <div class="stat">
      <div class="stat-n"><?= $ent['active'] ? '已开通' : '未开通' ?></div>
      <div class="stat-l"><?= $ent['active'] ? e((string)$ent['plan']['name']) : '月付套餐' ?></div>
    </div>
  </div>

  <form method="post" class="form" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="redeem">
    <div class="row-form">
      <label>兑换码<input type="text" name="code" placeholder="KBXXXX-XXXX-XXXX" autocomplete="off" required></label>
      <div style="align-self:flex-end"><button class="btn primary" type="submit">立即兑换</button></div>
    </div>
    <div class="field-hint">兑换码由管理员生成。一个码只能用一次（部分批次允许多人各用一次）。</div>
    <?php if ($shopUrl !== ''): ?>
      <div class="redeem-shop">
        <span class="muted">还没有兑换码？</span>
        <a class="btn sm" href="<?= e($shopUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($shopName !== '' ? '去 ' . $shopName . ' 购买' : '去购买兑换码') ?> →</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<section class="panel">
  <h2 class="panel-title">套餐</h2>
  <?php if (!$plans): ?>
    <p class="muted">管理员还没有配置套餐。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>名称</th><th>类型</th><th>价格</th><th>内容</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($plans as $p): ?>
            <?php $monthly = (string)$p['kind'] === 'monthly'; ?>
            <tr>
              <td>
                <b><?= e((string)$p['name']) ?></b>
                <?php if ((string)$p['note'] !== ''): ?><br><span class="tiny muted"><?= e((string)$p['note']) ?></span><?php endif; ?>
              </td>
              <td class="tiny"><span class="tag"><?= $monthly ? '月付' : '积分包' ?></span></td>
              <td class="tiny"><?= (int)$p['price_cents'] > 0 ? '¥' . number_format((int)$p['price_cents'] / 100, 2) : '免费' ?></td>
              <td class="tiny">
                <?php if ((int)$p['credits'] > 0): ?>送 <?= (int)$p['credits'] ?> 积分　<?php endif; ?>
                <?php if ($monthly): ?>
                  <?= (int)$p['days'] ?> 天 · 自带模型 <?= (int)$p['custom_slots'] ?> 个 ·
                  每日 <?= (int)$p['daily_calls'] > 0 ? (int)$p['daily_calls'] . ' 次' : '不限次' ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$p['price_cents'] > 0 && $payReady): ?>
                  <form method="post" action="<?= e(u('pay_go')) ?>" class="ai-bar js-pay" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <select name="method" style="min-width:104px">
                      <?php foreach ($payMethods as $mc => $ml): ?>
                        <option value="<?= e($mc) ?>"><?= e($ml) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn sm primary" type="submit">去支付</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="buy">
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn sm" type="submit">下单</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="hint" style="margin-top:12px">
      <?php if ($payReady): ?>
        <p><b>关于付款：</b>选好支付方式后点「去支付」，会在本页弹窗里完成付款（扫码或打开收银台），
          付完弹窗会自动显示「支付成功」，关掉弹窗即可看到新余额。</p>
        <p>没有在线支付习惯也可以用兑换码；对公转账等特殊情况联系管理员，由后台把订单标记为已支付。</p>
      <?php elseif ($payEnabled): ?>
        <p><b>在线支付正在配置中。</b>请先用兑换码自助充值，或联系管理员。</p>
      <?php else: ?>
        <p><b>关于付款：</b>当前没有开启在线支付。下单后会生成一个「待支付」订单，你可以
          ① 用兑换码自助充值；② 联系管理员付款，由后台把订单标记为已支付，系统自动发放。</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($orders): ?>
<section class="panel">
  <h2 class="panel-title">我的订单</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>单号</th><th>套餐</th><th>金额</th><th>方式</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td class="tiny mono"><?= e((string)$o['out_trade_no']) ?></td>
            <td class="tiny"><?= e((string)($o['plan_name'] ?? '—')) ?></td>
            <td class="tiny">¥<?= number_format((int)$o['amount_cents'] / 100, 2) ?></td>
            <td class="tiny">
              <?php if ((string)$o['channel'] === 'epay'): ?>
                <?= e(pay_method_name((string)($o['pay_method'] ?? ''))) ?>
              <?php elseif ((string)$o['channel'] === 'manual'): ?>
                后台标记
              <?php else: ?>
                <?= e((string)$o['channel']) ?>
              <?php endif; ?>
            </td>
            <td class="tiny">
              <?php
              $st = (string)$o['status'];
              $label = ['pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭', 'refunded' => '已退款'][$st] ?? $st;
              ?>
              <span class="tag <?= $st === 'paid' ? 'ok' : '' ?>"><?= e($label) ?></span>
              <?php $rs = (string)($o['refund_state'] ?? 'none'); if ($rs !== '' && $rs !== 'none'): ?>
                <br><span class="tag <?= $rs === 'pending' ? 'warn' : ($rs === 'done' ? 'ok' : '') ?>"
                      title="<?= e((string)($o['refund_note'] ?? '')) ?>"><?= e(pay_refund_state_label($rs)) ?></span>
                <?php if ($rs === 'rejected' && (string)($o['refund_note'] ?? '') !== ''): ?>
                  <br><span class="tiny muted"><?= e((string)$o['refund_note']) ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="tiny muted"><?= e((string)$o['created_at']) ?></td>
            <td class="tiny">
              <?php if ($st === 'pending' && (string)$o['channel'] === 'epay'): ?>
                <form method="post" action="<?= e(u('pay_go')) ?>" class="js-pay" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <input type="hidden" name="method" value="<?= e((string)($o['pay_method'] ?: (array_key_first($payMethods) ?: 'alipay'))) ?>">
                  <button class="btn sm primary" type="submit">继续支付</button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="refresh">
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <button class="btn sm" type="submit">刷新状态</button>
                </form>
              <?php elseif ($st === 'pending'): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('关闭后这单就不能再付款了，确认？');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="close_order">
                  <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                  <button class="btn sm" type="submit">关闭</button>
                </form>
              <?php elseif ($st === 'paid' && (string)($o['refund_state'] ?? 'none') === 'pending'): ?>
                <span class="tiny muted">等管理员处理</span>
              <?php elseif ($st === 'paid'): ?>
                <details class="refund-req">
                  <summary class="link-btn">申请退款</summary>
                  <form method="post" class="refund-form" onsubmit="return confirm('提交后要等管理员同意，同意后自动退回原支付渠道。确定提交？');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refund_request">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <textarea name="reason" rows="2" required minlength="4" maxlength="500"
                              placeholder="退款原因（至少 4 个字），比如：重复下单 / 用不上 / 买错套餐"></textarea>
                    <button class="btn sm primary" type="submit">提交申请</button>
                  </form>
                </details>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="tiny muted" style="margin-top:10px">已支付的订单可以申请退款：提交原因 → 管理员同意 → 系统自动退回原支付渠道（积分会同步扣回）。</p>
</section>
<?php endif; ?>

<?php if ($logs): ?>
<section class="panel">
  <h2 class="panel-title">积分流水</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>时间</th><th>变动</th><th>余额</th><th>来源</th><th>说明</th></tr></thead>
      <tbody>
        <?php
        $reasonMap = [
            'ai_call' => 'AI 调用', 'refund' => '失败退回', 'plan_grant' => '套餐赠送',
            'redeem' => '兑换码', 'admin' => '管理员调整', 'pay' => '在线支付',
        ];
        ?>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="tiny muted"><?= e((string)$l['created_at']) ?></td>
            <td class="tiny mono" style="color:<?= (int)$l['delta'] > 0 ? 'var(--ok)' : 'var(--text-2)' ?>">
              <?= (int)$l['delta'] > 0 ? '+' : '' ?><?= (int)$l['delta'] ?>
            </td>
            <td class="tiny mono"><?= (int)$l['balance'] ?></td>
            <td class="tiny"><?= e($reasonMap[(string)$l['reason']] ?? (string)$l['reason']) ?></td>
            <td class="tiny muted"><?= e((string)$l['note']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- 支付弹窗（1.9.1）：不跳页，在本页完成支付并自动显示结果 -->
<div class="pay-mask" id="payMask" aria-hidden="true">
  <div class="pay-box" role="dialog" aria-modal="true" aria-labelledby="payTitle">
    <div class="pay-head">
      <b id="payTitle">支付</b>
      <button class="pay-x" type="button" id="payX" aria-label="关闭">×</button>
    </div>
    <div class="pay-body" id="payBody"></div>
  </div>
</div>

<script src="<?= e(asset('qr.js')) ?>?v=<?= e(asset_ver('qr.js')) ?>"></script>
<script>
(function () {
  var payGo = <?= json_encode(u('pay_go')) ?>;
  var csrf = <?= json_encode(csrf_token()) ?>;
  var root = document.documentElement;
  var theme = root.getAttribute('data-theme') || 'light';
  var dark = theme === 'dark' || (theme === 'system' && window.matchMedia
    && window.matchMedia('(prefers-color-scheme: dark)').matches);
  var isMobile = /android|iphone|ipad|ipod|harmonyos|mobile/i.test(navigator.userAgent);

  var mask = document.getElementById('payMask');
  var body = document.getElementById('payBody');
  var titleEl = document.getElementById('payTitle');
  var closeBtn = document.getElementById('payX');

  var timer = null;
  var statusUrl = '';
  var paid = false;
  var busy = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function withDark(u) {
    var s = String(u);
    var own = s.charAt(0) === '/' || s.indexOf(location.origin) === 0;
    if (!own) { return u; }
    return s + (s.indexOf('?') >= 0 ? '&' : '?') + 'dark=' + (dark ? '1' : '0');
  }

  function openModal(title) {
    titleEl.textContent = title || '支付';
    mask.classList.add('on');
    mask.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(reload) {
    stopPoll();
    mask.classList.remove('on');
    mask.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (reload) { location.reload(); }
  }

  function stopPoll() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  function startPoll() {
    stopPoll();
    timer = setInterval(function () { pollOnce(false); }, 2500);
  }

  function pollOnce(manual) {
    if (!statusUrl || paid) { return; }
    var st = document.getElementById('payState');
    if (manual && st) { st.textContent = '正在向支付平台确认…'; }
    fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var j;
        try { j = JSON.parse(t); } catch (e) {
          // 会话过期时后端会返回登录页，这里给个明确提示，别让用户干等
          var box = document.getElementById('payState');
          if (box) { box.textContent = '登录状态可能已过期，请关闭弹窗、刷新页面后到「我的订单」查看结果'; }
          return;
        }
        if (!j || !j.ok || !j.data) { return; }
        var d = j.data;
        if (d.paid) { paid = true; stopPoll(); showSuccess(d); return; }
        var el = document.getElementById('payState');
        if (el) { el.textContent = d.msg ? d.msg : '等待支付…'; }
      })
      .catch(function () { /* 网络抖动就等下一轮 */ });
  }

  function showError(msg) {
    titleEl.textContent = '支付未完成';
    body.innerHTML = '<p class="ai-err" style="margin:0 0 12px">' + esc(msg) + '</p>' +
      '<p><button class="btn" type="button" id="payClose2">关闭</button></p>';
    document.getElementById('payClose2').addEventListener('click', function () { closeModal(false); });
  }

  function showSuccess(d) {
    titleEl.textContent = '支付成功';
    var box = mask.querySelector('.pay-box');
    if (box) { box.classList.remove('wide'); }
    var got = d.credits > 0 ? ('已到账 ' + d.credits + ' 积分，') : '';
    body.innerHTML = '<div class="pay-ok">' +
      '<div class="ico">✓</div>' +
      '<p class="muted">' + esc(got) + '当前余额 ' + (d.balance | 0) + ' 积分。</p>' +
      '<p class="muted">请关闭本窗口。</p>' +
      '<p><button class="btn primary" type="button" id="payDone">关闭</button></p>' +
      '</div>';
    document.getElementById('payDone').addEventListener('click', function () { closeModal(true); });
  }

  function render(d) {
    statusUrl = d.status_url || '';
    paid = false;
    var qr = String(d.qr || '');
    // 手机自己扫不了自己的屏幕，直接给 iframe / 新窗口
    var useQr = qr !== '' && !isMobile;

    titleEl.textContent = useQr ? '扫码支付' : '完成支付';
    var box = mask.querySelector('.pay-box');
    if (box) { box.classList.toggle('wide', !useQr); }

    var h = '<div class="pay-sum">' +
      '<span class="amt">¥' + esc(d.amount) + '</span>' +
      '<span class="muted">' + esc(d.plan) + '</span>' +
      '<span class="muted">' + esc(d.method_name) + '</span>' +
      '</div>';

    if (useQr) {
      h += '<div class="pay-qr" id="payQr"></div>';
      h += '<p class="pay-tip" style="text-align:center">请用手机「' + esc(d.method_name) +
        '」扫描二维码完成支付。<br>付款成功后本窗口会自动显示结果。</p>';
    } else {
      h += '<iframe class="pay-frame" id="payFrame" src="' + esc(withDark(d.frame)) + '" title="支付"></iframe>';
      h += '<p class="pay-tip">上方是支付平台的页面。如果显示空白、或无法完成付款，请点 ' +
        '<a href="' + esc(withDark(d.open)) + '" target="_blank" rel="noopener noreferrer">在新窗口完成支付</a>。</p>';
    }

    h += '<div class="pay-state"><span><span class="pay-dot"></span><span id="payState">等待支付…</span></span>' +
      '<button class="btn sm" type="button" id="payCheck">我已完成支付</button></div>';
    h += '<div class="pay-tip">单号 ' + esc(d.no) + '</div>';

    body.innerHTML = h;

    if (useQr) {
      var box = document.getElementById('payQr');
      if (window.KBQR) {
        try {
          KBQR.render(box, qr, { quiet: 2 });
        } catch (e) {
          box.innerHTML = '<p class="tiny muted" style="padding:10px;margin:0">二维码生成失败，请点「我已完成支付」刷新，或回充值页重试。</p>';
        }
      }
    }
    document.getElementById('payCheck').addEventListener('click', function () { pollOnce(true); });
    startPoll();
  }

  function start(form) {
    if (busy) { return; }
    busy = true;
    openModal('正在创建订单');
    body.innerHTML = '<p class="muted">正在创建订单…</p>';

    var fd = new FormData(form);
    fd.set('ajax', '1');
    fd.set('_csrf', csrf);

    fetch(payGo, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          try { return JSON.parse(t); } catch (e) { throw new Error('服务器返回异常，请刷新页面后重试'); }
        });
      })
      .then(function (j) {
        busy = false;
        if (!j || !j.ok) {
          showError((j && j.error && j.error.message) || '发起支付失败');
          return;
        }
        render(j.data);
      })
      .catch(function (e) {
        busy = false;
        showError(e && e.message ? e.message : '网络异常，请稍后重试');
      });
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.classList || !form.classList.contains('js-pay')) { return; }
    ev.preventDefault();
    start(form);
  });

  closeBtn.addEventListener('click', function () { closeModal(true); });
  mask.addEventListener('click', function (ev) { if (ev.target === mask) { closeModal(true); } });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && mask.classList.contains('on')) { closeModal(true); }
  });
})();
</script>
