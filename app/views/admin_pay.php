<?php
/**
 * 后台：支付设置 + 订单对账
 * @var array $conf @var bool $ready @var string $keyMask @var array $methodsAll
 * @var array $orders @var array $events @var array $stat
 * @var array $refunds @var array $ledger @var array $ledgerSum @var array $creditLogs
 * @var array|null $refundProbe
 */
$route = 'admin_pay';
$stLabel = ['pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭', 'refunded' => '已退款'];
$kindLabel = ['credits' => '积分包', 'monthly' => '月付'];
$issueMap = pay_issue_map();
$ledger = $ledger ?? [];
$ledgerSum = $ledgerSum ?? ['total' => 0, 'paid' => 0, 'refunded' => 0, 'pending' => 0, 'abnormal' => 0,
    'granted' => 0, 'reclaimed' => 0, 'net' => 0, 'amount_paid' => 0, 'amount_refunded' => 0, 'by_code' => []];
$creditLogs = $creditLogs ?? [];
$badRows = array_values(array_filter($ledger, fn($r) => !empty($r['issues'])));
$money = fn(int $c): string => '¥' . number_format($c / 100, 2);

/** 订单行（订单列表与抽屉共用） */
$renderOrder = function (array $o) use ($stLabel, $kindLabel, $money): void {
    $st = (string)$o['status'];
    $isEpay = (string)$o['channel'] === 'epay';
    $rs = (string)($o['refund_state'] ?? 'none');
    ?>
    <tr>
      <td class="tiny muted" style="white-space:nowrap"><?= e((string)$o['created_at']) ?></td>
      <td class="tiny"><?= e((string)($o['username'] ?? ('#' . (int)$o['user_id']))) ?></td>
      <td class="tiny">
        <?= e((string)($o['plan_name'] ?? '—')) ?>
        <span class="tag" style="margin-left:4px"><?= e($kindLabel[(string)$o['kind']] ?? (string)$o['kind']) ?></span>
      </td>
      <td class="tiny mono"><?= $money((int)$o['amount_cents']) ?></td>
      <td class="tiny"><?= e($isEpay ? pay_method_name((string)($o['pay_method'] ?? '')) : ((string)$o['channel'] === 'manual' ? '手动' : (string)$o['channel'])) ?></td>
      <td class="tiny">
        <span class="tag <?= $st === 'paid' ? 'ok' : ($st === 'refunded' ? 'warn' : '') ?>"><?= e($stLabel[$st] ?? $st) ?></span>
        <?php if ($rs !== '' && $rs !== 'none'): ?>
          <span class="tag <?= $rs === 'pending' ? 'warn' : ($rs === 'done' ? 'ok' : '') ?>"
                title="<?= e((string)($o['refund_note'] ?? '')) ?>"><?= e(pay_refund_state_label($rs)) ?></span>
        <?php endif; ?>
        <?php if ($st === 'paid' && !empty($o['paid_at'])): ?>
          <div class="tiny muted"><?= e((string)$o['paid_at']) ?></div>
        <?php endif; ?>
      </td>
      <td class="tiny mono" style="word-break:break-all;max-width:180px">
        <?= e((string)$o['out_trade_no']) ?>
        <?php if (!empty($o['transaction_id'])): ?>
          <div class="muted"><?= e((string)$o['transaction_id']) ?></div>
        <?php endif; ?>
      </td>
      <td class="tiny" style="white-space:nowrap">
        <?php if ($isEpay && $st === 'pending'): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="query">
            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
            <button class="btn sm" type="submit">查单 / 补发货</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('确认关闭这笔待支付订单？');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
            <button class="btn sm" type="submit">关闭</button>
          </form>
        <?php elseif ($isEpay && $st === 'paid'): ?>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <form method="post" style="display:inline"
                  onsubmit="return confirm(<?= e(json_encode(
                      '确认对订单 ' . $o['out_trade_no'] . ' 发起退款 ' . $money((int)$o['amount_cents'])
                      . "？退款会调用平台接口，成功后本单标记为已退款，已发积分（含月付赠送）能扣回就扣回。\n\n若报「商户未开启退款API接口」，先用上面的「退款接口自检」判断，或改用「线下已退」。",
                      JSON_UNESCAPED_UNICODE)) ?>);">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="refund">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <button class="btn sm" type="submit">退款</button>
            </form>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('【重要】「线下已退」不会调用支付平台，只是在本站把订单标记为已退款并回收积分/套餐。\n\n请先确认钱已经真正退给用户。\n\n确定要标记吗？');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="refund_manual">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="note" value="平台未开放退款接口或已线下退款">
              <button class="btn sm" type="submit" title="不调接口，只在站内标记为已退款">线下已退</button>
            </form>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="query">
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <button class="btn sm" type="submit" title="查平台状态；若平台已退款会自动同步">查单 / 同步</button>
            </form>
          </div>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php
};

/** 退款申请行 */
$renderRefund = function (array $r) use ($money): void {
    $isEpayR = (string)$r['channel'] === 'epay' && (string)($r['out_trade_no'] ?? '') !== '';
    $confirmMsg = $isEpayR
        ? '同意后立即调用平台退款 ' . $money((int)$r['amount_cents']) . '，确认？'
        : '这单不是在线支付，同意后只会标记已退款并回收权益，钱要你自己退。继续？';
    ?>
    <tr>
      <td class="tiny muted" style="white-space:nowrap"><?= e((string)($r['updated_at'] ?? '')) ?></td>
      <td class="tiny"><?= e((string)($r['username'] ?? ('#' . (int)$r['user_id']))) ?></td>
      <td class="tiny"><?= e((string)($r['plan_name'] ?? '—')) ?></td>
      <td class="tiny mono"><?= $money((int)$r['amount_cents']) ?></td>
      <td class="tiny" style="max-width:240px;white-space:normal"><?= e((string)($r['refund_reason'] ?? '')) ?></td>
      <td class="tiny" style="white-space:nowrap">
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"
              onsubmit="return confirm(<?= e(json_encode($confirmMsg, JSON_UNESCAPED_UNICODE)) ?>);">
          <?= csrf_field() ?>
          <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="note" placeholder="备注（可选）" style="width:100px;font-size:12px;padding:4px 7px;border:1px solid var(--border-strong);border-radius:7px;background:var(--panel-2);color:var(--text)">
          <button class="btn sm primary" type="submit" name="action" value="refund_approve">同意退款</button>
          <button class="btn sm" type="submit" name="action" value="refund_reject" onclick="return confirm('驳回后用户会看到驳回说明，确定？')">驳回</button>
        </form>
        <?php if (!$isEpayR): ?><div class="tiny muted">非在线支付，钱需线下退</div><?php endif; ?>
      </td>
    </tr>
    <?php
};
?>
<section class="page-head">
  <div>
    <h1 class="page-title">支付设置</h1>
    <p class="page-desc">在线收款走彩虹易支付（V1 接口，MD5 签名）。详细配置都收在下面的折叠块里。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('admin_ai')) ?>">套餐与定价</a>
    <a class="btn" href="<?= e(u('recharge')) ?>">前台充值页</a>
  </div>
</section>

<section class="panel">
  <div class="mini-stats">
    <span>在线支付
      <b style="color:<?= $ready ? 'var(--ok)' : 'var(--err)' ?>"><?= $ready ? '已开启' : ($conf['enabled'] ? '配置不全' : '未开启') ?></b>
    </span>
    <span>今日 <?= $money((int)$stat['amount_today']) ?> <b>(<?= (int)$stat['paid_today'] ?> 笔)</b></span>
    <span>累计 <?= $money((int)$stat['amount_all']) ?></span>
    <span>待支付 <b><?= (int)$stat['pending'] ?></b></span>
    <span>对账异常 <b class="<?= (int)$ledgerSum['abnormal'] > 0 ? 'bad' : '' ?>"><?= (int)$ledgerSum['abnormal'] ?></b></span>
  </div>
  <?php if (!$ready): ?>
    <div class="hint" style="margin-top:12px">
      <p><b>还没生效。</b>需要三样齐全：商户 ID、商户密钥、至少一个支付方式。在易支付商户后台「个人资料 → API 信息」可以拿到。</p>
    </div>
  <?php endif; ?>
</section>

<!-- ============ 订单与积分对账 ============ -->
<section class="panel">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 class="panel-title" style="margin:0">订单与积分对账</h2>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn sm" type="button" data-sheet="sheet-ledger">对账明细（<?= (int)$ledgerSum['total'] ?>）</button>
      <button class="btn sm" type="button" data-sheet="sheet-credits">积分流水（<?= count($creditLogs) ?>）</button>
    </div>
  </div>
  <p class="tiny muted" style="margin:6px 0 10px">
    每笔单都对三个数：<b>应发</b>（套餐赠送）→ <b>实发</b>（user_plans 记录）→ <b>已扣回</b>（退款时扣的）。
    <b>净发放 &gt; 0</b> 表示这份权益还在用户手里 —— 已退款的单还留着净发放，就是白拿。
  </p>
  <div class="mini-stats">
    <span>已支付 <b><?= (int)$ledgerSum['paid'] ?></b>（<?= $money((int)$ledgerSum['amount_paid']) ?>）</span>
    <span>已退款 <b><?= (int)$ledgerSum['refunded'] ?></b>（<?= $money((int)$ledgerSum['amount_refunded']) ?>）</span>
    <span>发放积分 <b><?= (int)$ledgerSum['granted'] ?></b></span>
    <span>已扣回 <b><?= (int)$ledgerSum['reclaimed'] ?></b></span>
    <span>净发放 <b class="<?= (int)$ledgerSum['net'] > 0 ? 'bad' : '' ?>"><?= (int)$ledgerSum['net'] ?></b></span>
  </div>

  <?php if ($badRows): ?>
    <div class="hint" style="margin-top:12px;border-left:3px solid var(--err)">
      <p><b><?= count($badRows) ?> 笔需要看一眼</b>
        <?php foreach (($ledgerSum['by_code'] ?? []) as $code => $n): ?>
          <span class="issue-tag"><?= e($issueMap[$code][0] ?? $code) ?> ×<?= (int)$n ?></span>
        <?php endforeach; ?>
      </p>
      <div class="table-wrap">
        <table class="table ledger-table">
          <thead><tr><th>时间</th><th>用户</th><th>套餐</th><th style="text-align:right">金额</th><th>状态</th><th style="text-align:right">实发</th><th style="text-align:right">扣回</th><th style="text-align:right">净</th><th>问题</th></tr></thead>
          <tbody>
            <?php foreach (array_slice($badRows, 0, 5) as $r): ?>
              <tr class="has-issue">
                <td class="tiny muted" style="white-space:nowrap"><?= e((string)$r['created_at']) ?></td>
                <td class="tiny"><?= e((string)($r['username'] ?? ('#' . (int)$r['user_id']))) ?></td>
                <td class="tiny"><?= e((string)($r['plan_name'] ?? '—')) ?></td>
                <td class="num"><?= $money((int)$r['amount_cents']) ?></td>
                <td class="tiny"><?= e($stLabel[(string)$r['status']] ?? (string)$r['status']) ?></td>
                <td class="num"><?= (int)$r['granted'] ?></td>
                <td class="num"><?= (int)$r['reclaimed'] ?></td>
                <td class="num"><b><?= (int)$r['net'] ?></b></td>
                <td class="tiny">
                  <?php foreach ($r['issues'] as $it): ?>
                    <span class="issue-tag <?= ($issueMap[$it[0]][1] ?? 1) > 1 ? 'soft' : '' ?>" title="<?= e($it[1]) ?>"><?= e($issueMap[$it[0]][0] ?? $it[0]) ?></span>
                  <?php endforeach; ?>
                  <?php if (in_array('unreclaimed', array_column($r['issues'], 0), true)): ?>
                    <form method="post" style="margin-top:5px"
                          onsubmit="return confirm('从该用户余额里补扣这单还没收回的积分？余额不够就扣到 0，差额会记在订单备注里。');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reclaim">
                      <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
                      <button class="btn sm" type="submit">补扣积分</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($badRows) > 5): ?>
        <p class="tiny muted" style="margin:8px 0 0">还有 <?= count($badRows) - 5 ?> 笔，见「对账明细」。</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <p class="tiny" style="margin:12px 0 0;color:var(--ok)">✓ 暂无明显异常：已支付的都发了货，已退款的都收回或已标记差额。</p>
  <?php endif; ?>
</section>

<?php if (!empty($refunds)): ?>
<section class="panel refund-panel">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 class="panel-title" style="margin:0">退款申请（<?= count($refunds) ?> 条待处理）</h2>
    <?php if (count($refunds) > 3): ?>
      <button class="btn sm" type="button" style="margin-left:auto" data-sheet="sheet-refunds">全部 <?= count($refunds) ?> 条</button>
    <?php endif; ?>
  </div>
  <p class="tiny muted" style="margin:6px 0 10px">
    在线单点「同意退款」会直接调平台退款；报「商户未开启退款API接口」时，先在下面「接口配置 → 退款接口自检」确认是哪个开关没开。
  </p>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>申请时间</th><th>用户</th><th>套餐</th><th>金额</th><th>原因</th><th>处理</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($refunds, 0, 3) as $r) { $renderRefund($r); } ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 class="panel-title" style="margin:0">最近订单</h2>
    <?php if (count($orders) > 10): ?>
      <button class="btn sm" type="button" style="margin-left:auto" data-sheet="sheet-orders">全部 <?= count($orders) ?> 笔</button>
    <?php endif; ?>
  </div>
  <?php if (!$orders): ?>
    <p class="muted">还没有在线支付订单。</p>
  <?php else: ?>
    <div class="table-wrap" style="margin-top:10px">
      <table class="table">
        <thead><tr><th>时间</th><th>用户</th><th>套餐</th><th>金额</th><th>方式</th><th>状态</th><th>单号 / 流水</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($orders, 0, 10) as $o) { $renderOrder($o); } ?>
        </tbody>
      </table>
    </div>
    <?php if (count($orders) > 10): ?>
      <p class="tiny muted" style="margin:8px 0 0">只显示最近 10 笔，其余见右上「全部」。</p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<!-- ============ 接口配置（折叠） ============ -->
<section class="panel">
  <details class="fold">
    <summary>
      接口配置
      <span class="fold-note">
        <?= e((string)$conf['api']) ?> · 商户 <?= e((string)$conf['pid'] !== '' ? (string)$conf['pid'] : '未填') ?>
        · <?= e(implode('/', array_map(fn($m) => pay_method_name($m), $conf['methods']))) ?>
      </span>
    </summary>
    <div class="fold-body">
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <div class="row-form">
          <label>接口地址
            <input type="text" name="pay_api" value="<?= e((string)$conf['api']) ?>" placeholder="https://pay.cfxcc.cn" required>
          </label>
          <label>商户 ID（pid）
            <input type="text" name="pay_pid" value="<?= e((string)$conf['pid']) ?>" placeholder="1001" autocomplete="off">
          </label>
        </div>

        <div class="row-form">
          <label>商户密钥（KEY）
            <input type="text" name="pay_key" value="" autocomplete="new-password"
                   placeholder="<?= $keyMask !== '' ? '已保存：' . e($keyMask) . '（留空表示不修改）' : '在商户后台 API 信息里查看' ?>">
          </label>
          <label>商品名前缀
            <input type="text" name="pay_title_prefix" value="<?= e((string)$conf['title_prefix']) ?>" placeholder="知识库 · ">
          </label>
        </div>
        <div class="field-hint">密钥用 AES-256-GCM 加密存库（密钥来自 <code>config.php</code> 的 pepper）。备份数据库时请一并备份 <code>config.php</code>。</div>

        <div style="margin-top:12px">
          <div class="field-hint" style="margin-bottom:6px">开放的支付方式（前台让用户选）</div>
          <?php foreach ($methodsAll as $code => $label): ?>
            <label style="display:inline-flex;flex-direction:row;align-items:center;gap:6px;margin-right:16px;font-weight:400">
              <input type="checkbox" name="methods[]" value="<?= e($code) ?>"
                     <?= in_array($code, $conf['methods'], true) ? 'checked' : '' ?>>
              <?= e($label) ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:14px;display:grid;gap:8px">
          <label style="display:flex;flex-direction:row;align-items:center;gap:8px;font-weight:400">
            <input type="checkbox" name="pay_use_mapi" value="1" <?= $conf['use_mapi'] ? 'checked' : '' ?>>
            优先用接口支付（拿支付链接直接跳转；关闭则一律走平台收银台页面）
          </label>
          <label style="display:flex;flex-direction:row;align-items:center;gap:8px;font-weight:400">
            <input type="checkbox" name="pay_enabled" value="1" <?= $conf['enabled'] ? 'checked' : '' ?>>
            <b>开启在线支付</b>
          </label>
        </div>

        <div class="ai-bar" style="margin-top:16px">
          <button class="btn primary" type="submit">保存</button>
        </div>
      </form>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="test">
          <button class="btn" type="submit">测试连接</button>
          <span class="tiny muted">查一次商户余额与状态，不产生订单</span>
        </form>
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="refund_probe">
          <button class="btn" type="submit">退款接口自检</button>
          <span class="tiny muted">用不存在的单号探一次，<b>不退真钱</b></span>
        </form>
      </div>

      <div class="hint" style="margin-top:14px">
        <p><b>回调地址（下单时自动带上，商户后台不用填）</b></p>
        <p class="tiny mono" style="word-break:break-all">异步通知：<?= e((string)$conf['notify_url']) ?></p>
        <p class="tiny mono" style="word-break:break-all">同步跳转：<?= e((string)$conf['return_url']) ?></p>
        <p class="tiny">必须是公网能访问的完整域名。站点在 CDN / 防火墙后面时，把 <code>/index.php?r=pay_notify</code> 加进白名单（回调由平台服务器直接访问，不带登录态）。</p>
      </div>
    </div>
  </details>

  <?php $rp = $refundProbe ?? null; if (is_array($rp)): ?>
    <?php
    $pr = (array)($rp['probe'] ?? []);
    $rf = (array)($rp['refund'] ?? []);
    $rfs = (string)($rf['state'] ?? 'na');
    $rfTag = $rfs === 'allowed' ? ['ok', '已放行'] : ($rfs === 'denied' ? ['warn', '未开启'] : ['', '未知']);
    ?>
    <details class="fold" <?= $rfs === 'denied' ? 'open' : '' ?>>
      <summary>
        退款接口自检结果
        <span class="fold-note"><?= e((string)($rp['at'] ?? '')) ?> · <span class="tag <?= $rfTag[0] ?>"><?= e($rfTag[1]) ?></span></span>
      </summary>
      <div class="fold-body">
        <div class="table-wrap">
          <table class="table">
            <tbody>
              <tr>
                <th style="width:104px">探测商户号</th>
                <td>
                  <span class="mono"><?= e((string)$conf['pid']) ?></span>
                  <span class="tiny muted" style="margin-left:8px">自建平台常有多商户，确认你开开关的是这个号</span>
                </td>
              </tr>
              <tr>
                <th>商户信息</th>
                <td>
                  <span class="tag <?= !empty($pr['ok']) ? 'ok' : 'warn' ?>"><?= !empty($pr['ok']) ? '正常' : '有问题' ?></span>
                  <span class="tiny" style="margin-left:6px;white-space:normal"><?= e((string)($pr['message'] ?? '未取到')) ?></span>
                </td>
              </tr>
              <tr>
                <th>退款 API</th>
                <td>
                  <span class="tag <?= $rfTag[0] ?>"><?= e($rfTag[1]) ?></span>
                  <span class="tiny" style="margin-left:6px;white-space:normal"><?= e((string)($rf['msg'] ?? '')) ?></span>
                  <?php if (!empty($rf['probe_no'])): ?>
                    <div class="tiny muted mono">探测单号：<?= e((string)$rf['probe_no']) ?></div>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <?php if ($rfs === 'denied'): ?>
          <div class="hint" style="margin-top:12px">
            <p><b>平台侧确实没给这个商户号开「订单退款 API」。</b>两个开关不是一回事：</p>
            <ul style="margin:6px 0 6px 18px">
              <li><b>开启商户自助退款</b> —— 只允许你在商户后台点按钮退款，程序调用不算数；</li>
              <li><b>订单退款 API</b> —— 才允许本系统调 <code>api.php?act=refund</code>。</li>
            </ul>
            <p>开法：用 <b>商户号 <?= e((string)$conf['pid']) ?></b> 登录 <span class="mono"><?= e((string)$conf['api']) ?></span> 的<b>商户后台</b>（不是平台管理员后台），
               在「个人资料 / API 信息」或「商户设置」里找 <b>订单退款 API</b> 并保存。找不到就把这句发给客服：</p>
            <p class="tiny mono" style="word-break:break-all">我的商户号 <?= e((string)$conf['pid']) ?> 调用 POST /api.php?act=refund 返回「商户未开启订单退款API接口」，请帮我开退款 API。</p>
            <p>开通前想先把账做平：平台后台手工退款 → 回下面订单点「查单 / 同步」（自动识别平台已退款）或「线下已退」。</p>
          </div>
        <?php elseif ($rfs === 'allowed'): ?>
          <div class="hint" style="margin-top:12px">
            <p><b>退款接口是通的</b>，平台没在权限上拦。之前那笔失败是别的原因：</p>
            <ul style="margin:6px 0 6px 18px">
              <li>该单的 <code>trade_no</code> 为空或错位 —— 走 <code>out_trade_no</code> 才对；</li>
              <li>退款金额：文档写明「少数通道需要与原订单金额一致」，部分退过的单对不上；</li>
              <li>该渠道不支持原路退（某些小额通道），换一单试就知道；</li>
              <li>看页面底部「支付事件日志」那次 <code>refund</code> 的平台原话。</li>
            </ul>
          </div>
        <?php else: ?>
          <div class="hint" style="margin-top:12px">
            <p>没拿到明确结论。把这行发给平台客服：</p>
            <p class="tiny mono" style="word-break:break-all">商户 <?= e((string)$conf['pid']) ?>，调用 POST <?= e((string)$conf['api']) ?>/api.php?act=refund，返回：<?= e((string)($rf['msg'] ?? '无响应')) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </details>
  <?php endif; ?>

  <details class="fold">
    <summary>支付事件日志 <span class="fold-note">发起 / 回调 / 查单 / 退款，最近 40 条</span></summary>
    <div class="fold-body">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>时间</th><th>类型</th><th>订单号</th><th>签名</th><th>金额</th><th>结果</th><th>IP</th></tr></thead>
          <tbody>
            <?php if (!$events): ?>
              <tr><td colspan="7" class="muted tiny">暂无记录</td></tr>
            <?php endif; ?>
            <?php foreach ($events as $e): ?>
              <tr>
                <td class="tiny muted"><?= e((string)$e['created_at']) ?></td>
                <td class="tiny">
                  <?php $k = (string)$e['kind']; ?>
                  <span class="tag"><?= e(['start' => '发起', 'notify' => '回调', 'query' => '查单', 'refund' => '退款', 'refund_sync' => '退款同步', 'refund_manual' => '线下已退'][$k] ?? $k) ?></span>
                </td>
                <td class="tiny mono"><?= e((string)$e['out_trade_no']) ?></td>
                <td class="tiny"><?= (int)$e['sign_ok'] === 1 ? '✓' : '<span style="color:var(--err)">✗</span>' ?></td>
                <td class="tiny mono"><?= (int)$e['amount_cents'] > 0 ? $money((int)$e['amount_cents']) : '—' ?></td>
                <td class="tiny"><?= e((string)$e['result']) ?></td>
                <td class="tiny muted mono"><?= e((string)$e['ip']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="field-hint" style="margin-top:8px">「签名 ✗」说明回调没通过验签（密钥填错 / notify 地址被改），这类通知不会发货。</div>
    </div>
  </details>
</section>

<!-- ============ 抽屉：对账明细 ============ -->
<div class="sheet-wrap" id="sheet-ledger" hidden>
  <div class="sheet-mask" data-sheet-close></div>
  <aside class="sheet">
    <div class="sheet-head">
      <div>
        <h2 class="sheet-title">对账明细</h2>
        <p class="sheet-sub">最近 <?= count($ledger) ?> 笔 · 应发 → 实发 → 已扣回 → 净发放</p>
      </div>
      <button class="sheet-close" type="button" data-sheet-close aria-label="关闭">×</button>
    </div>
    <div class="sheet-body">
      <div class="table-wrap">
        <table class="table ledger-table">
          <thead>
            <tr>
              <th>时间</th><th>用户</th><th>套餐</th>
              <th style="text-align:right">金额</th><th>状态</th>
              <th style="text-align:right">应发</th><th style="text-align:right">实发</th>
              <th style="text-align:right">扣回</th><th style="text-align:right">净</th><th>问题</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ledger as $r): ?>
              <tr class="<?= $r['issues'] ? 'has-issue' : '' ?>">
                <td class="tiny muted" style="white-space:nowrap"><?= e((string)$r['created_at']) ?></td>
                <td class="tiny"><?= e((string)($r['username'] ?? ('#' . (int)$r['user_id']))) ?></td>
                <td class="tiny"><?= e((string)($r['plan_name'] ?? '—')) ?></td>
                <td class="num"><?= $money((int)$r['amount_cents']) ?></td>
                <td class="tiny"><?= e($stLabel[(string)$r['status']] ?? (string)$r['status']) ?></td>
                <td class="num"><?= (int)$r['credits'] ?></td>
                <td class="num"><?= (int)$r['granted'] ?></td>
                <td class="num"><?= (int)$r['reclaimed'] ?></td>
                <td class="num"><b><?= (int)$r['net'] ?></b></td>
                <td class="tiny">
                  <?php foreach ($r['issues'] as $it): ?>
                    <span class="issue-tag <?= ($issueMap[$it[0]][1] ?? 1) > 1 ? 'soft' : '' ?>" title="<?= e($it[1]) ?>"><?= e($issueMap[$it[0]][0] ?? $it[0]) ?></span>
                  <?php endforeach; ?>
                  <?php if (in_array('unreclaimed', array_column($r['issues'], 0), true)): ?>
                    <form method="post" style="margin-top:5px"
                          onsubmit="return confirm('从该用户余额里补扣这单还没收回的积分？余额不够就扣到 0，差额会记在订单备注里。');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reclaim">
                      <input type="hidden" name="order_id" value="<?= (int)$r['id'] ?>">
                      <button class="btn sm" type="submit">补扣积分</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!$r['issues']): ?><span class="muted">—</span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$ledger): ?><tr><td colspan="10" class="muted tiny">还没有订单</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="field-hint" style="margin-top:10px">
        「净 = 实发 − 已扣回」。已退款单净 &gt; 0 说明积分没收回（用户已消耗，余额不够扣），需要人工追或标记坏账；
        已支付单实发 0 说明发货失败，点「查单 / 补发货」可以补。
      </div>
    </div>
  </aside>
</div>

<!-- ============ 抽屉：积分流水 ============ -->
<div class="sheet-wrap" id="sheet-credits" hidden>
  <div class="sheet-mask" data-sheet-close></div>
  <aside class="sheet">
    <div class="sheet-head">
      <div>
        <h2 class="sheet-title">积分流水</h2>
        <p class="sheet-sub">最近 <?= count($creditLogs) ?> 条 · 正数入账、负数消耗，balance 是当时余额</p>
      </div>
      <button class="sheet-close" type="button" data-sheet-close aria-label="关闭">×</button>
    </div>
    <div class="sheet-body">
      <div class="table-wrap">
        <table class="table ledger-table">
          <thead><tr><th>时间</th><th>用户</th><th style="text-align:right">变动</th><th style="text-align:right">余额</th><th>类型</th><th>关联</th><th>说明</th></tr></thead>
          <tbody>
            <?php foreach ($creditLogs as $l): ?>
              <?php $d = (int)$l['delta']; ?>
              <tr>
                <td class="tiny muted" style="white-space:nowrap"><?= e((string)$l['created_at']) ?></td>
                <td class="tiny"><?= e((string)($l['username'] ?? ('#' . (int)$l['user_id']))) ?></td>
                <td class="num" style="color:<?= $d < 0 ? 'var(--err)' : 'var(--ok)' ?>"><?= ($d > 0 ? '+' : '') . $d ?></td>
                <td class="num"><?= (int)$l['balance'] ?></td>
                <td class="tiny"><span class="tag"><?= e(pay_credit_reason_label((string)$l['reason'])) ?></span></td>
                <td class="tiny mono"><?= e((string)($l['ref_type'] ?? '') !== '' ? (string)$l['ref_type'] . '#' . (int)$l['ref_id'] : '—') ?></td>
                <td class="tiny" style="max-width:220px;white-space:normal"><?= e((string)($l['note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$creditLogs): ?><tr><td colspan="7" class="muted tiny">还没有积分变动</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="field-hint" style="margin-top:10px">
        对不上的时候按「关联」列的原单号回查订单：<code>order#12</code> 就是订单 12。
        退款扣回记成「退款扣回」，AI 调用记成「AI 调用」。
      </div>
    </div>
  </aside>
</div>

<!-- ============ 抽屉：全部订单 ============ -->
<div class="sheet-wrap" id="sheet-orders" hidden>
  <div class="sheet-mask" data-sheet-close></div>
  <aside class="sheet">
    <div class="sheet-head">
      <div>
        <h2 class="sheet-title">全部订单</h2>
        <p class="sheet-sub">最近 <?= count($orders) ?> 笔在线支付 / 待支付单</p>
      </div>
      <button class="sheet-close" type="button" data-sheet-close aria-label="关闭">×</button>
    </div>
    <div class="sheet-body">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>时间</th><th>用户</th><th>套餐</th><th>金额</th><th>方式</th><th>状态</th><th>单号 / 流水</th><th>操作</th></tr></thead>
          <tbody>
            <?php foreach ($orders as $o) { $renderOrder($o); } ?>
          </tbody>
        </table>
      </div>
    </div>
  </aside>
</div>

<?php if (!empty($refunds)): ?>
<!-- ============ 抽屉：全部退款申请 ============ -->
<div class="sheet-wrap" id="sheet-refunds" hidden>
  <div class="sheet-mask" data-sheet-close></div>
  <aside class="sheet">
    <div class="sheet-head">
      <div>
        <h2 class="sheet-title">退款申请</h2>
        <p class="sheet-sub"><?= count($refunds) ?> 条待处理</p>
      </div>
      <button class="sheet-close" type="button" data-sheet-close aria-label="关闭">×</button>
    </div>
    <div class="sheet-body">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>申请时间</th><th>用户</th><th>套餐</th><th>金额</th><th>原因</th><th>处理</th></tr></thead>
          <tbody>
            <?php foreach ($refunds as $r) { $renderRefund($r); } ?>
          </tbody>
        </table>
      </div>
    </div>
  </aside>
</div>
<?php endif; ?>
