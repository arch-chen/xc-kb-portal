<?php
/** @var array $models $plans $codes $orders $calls $stat $prompts $newCodes */
$route = 'admin_ai';
$yuan = fn(int $cents) => number_format($cents / 100, 2);
?>
<section class="page-head">
  <div>
    <h1 class="page-title">AI 设置</h1>
    <p class="page-desc">模型定价、套餐、兑换码、积分与调用日志。用户端按次扣积分，自带模型走月付套餐。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('admin')) ?>">返回后台</a>
  </div>
</section>

<?php if (!empty($diag)): ?>
<section class="panel diag-panel">
  <h2 class="panel-title">连通性诊断 · <?= e((string)($diag['name'] ?? '')) ?></h2>
  <p class="tiny muted" style="margin:2px 0 10px">目标：<code><?= e((string)($diag['target'] ?? '')) ?></code></p>
  <div class="table-wrap">
    <table class="table diag-table">
      <thead><tr><th style="width:96px">步骤</th><th style="width:70px">结果</th><th style="width:70px">耗时</th><th>详情</th></tr></thead>
      <tbody>
        <?php foreach ((array)($diag['steps'] ?? []) as $st): ?>
          <?php
          $state = (string)($st['state'] ?? 'skip');
          $mark = $state === 'ok' ? '✓' : ($state === 'fail' ? '✗' : '–');
          $cls = $state === 'ok' ? 'diag-ok' : ($state === 'fail' ? 'diag-fail' : 'diag-skip');
          ?>
          <tr>
            <td class="diag-step"><?= e((string)($st['name'] ?? '')) ?></td>
            <td class="<?= $cls ?>"><b><?= $mark ?></b></td>
            <td class="diag-ms"><?= (int)($st['ms'] ?? 0) > 0 ? (int)$st['ms'] . ' ms' : '—' ?></td>
            <td class="tiny" style="white-space:normal;word-break:break-word"><?= e((string)($st['detail'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($diag['ok'])): ?>
    <div class="diag-advice"><b>怎么处理：</b><?= e((string)($diag['advice'] ?? '')) ?></div>
  <?php else: ?>
    <div class="diag-advice diag-good"><b>结论：</b>网络链路是通的。如果调用还是失败，点下面的「深度诊断」看模型名/密钥/额度是否有问题。</div>
  <?php endif; ?>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="model_diag">
    <input type="hidden" name="id" value="<?= (int)($diag['id'] ?? 0) ?>">
    <input type="hidden" name="deep" value="1">
    <button class="btn sm" type="submit"
            onclick="return confirm('深度诊断会真发一次最小对话，验证模型名和密钥，会消耗一次额度。继续？')">深度诊断（含模型调用）</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <div class="ai-head">
    <div>
      <h2 class="panel-title" style="margin:0">总开关</h2>
      <p class="muted tiny" style="margin:4px 0 0">
        关闭后用户端「AI 助手」入口会提示未开启，已配置的模型和数据都保留。
      </p>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_ai">
      <input type="hidden" name="ai_enabled" value="<?= $on ? '0' : '1' ?>">
      <button class="btn <?= $on ? 'danger' : 'primary' ?>" type="submit"><?= $on ? '关闭 AI 功能' : '开启 AI 功能' ?></button>
    </form>
  </div>

  <div class="stat-grid" style="margin-top:14px">
    <div class="stat"><div class="stat-n"><?= (int)$stat['calls_today'] ?></div><div class="stat-l">今日调用</div></div>
    <div class="stat"><div class="stat-n"><?= (int)$stat['credits_today'] ?></div><div class="stat-l">今日消耗积分</div></div>
    <div class="stat"><div class="stat-n"><?= (int)$stat['users'] ?></div><div class="stat-l">有余额的用户</div></div>
    <div class="stat"><div class="stat-n"><?= (int)$stat['paid'] ?></div><div class="stat-l">已支付订单</div></div>
    <div class="stat"><div class="stat-n">¥<?= $yuan((int)$stat['revenue']) ?></div><div class="stat-l">累计成交</div></div>
  </div>

  <form method="post" class="form" style="margin-top:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="limits_save">
    <div class="row-form">
      <label>单次输入字符上限
        <input type="number" name="ai_max_input" value="<?= (int)$maxIn ?>" min="500" max="200000" step="500">
      </label>
      <label>工作台抓网页大小上限（KB）
        <input type="number" name="agent_fetch_max_kb" value="<?= (int)$fetchKb ?>" min="200" max="8000" step="100">
      </label>
      <div style="align-self:flex-end"><button class="btn" type="submit">保存</button></div>
    </div>
    <label style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-top:12px;font-weight:400">
      <input type="checkbox" name="agent_fetch_ssl_verify" value="1" <?= $fetchVerify ? 'checked' : '' ?>>
      抓网页时校验证书（服务器没装 CA 证书包、导致 HTTPS 一律失败时，可以临时关掉）
    </label>
    <div class="field-hint">超过这个长度的请求直接拒绝，防止把整库内容一次倒给模型（也防止一次扣太多积分）。</div>
  </form>
</section>

<section class="panel">
  <h2 class="panel-title">模型（<?= count($models) ?>）</h2>
  <div class="hint" style="margin-bottom:10px">
    <p>系统模型对所有登录用户开放，按「每次调用」扣积分；用户自带模型（右侧标注用户名）不扣积分，走月付套餐。</p>
    <p><b>常见填法：</b>OpenAI 兼容填到 <code>/v1</code>（如 <code>https://api.deepseek.com/v1</code>）；
      Anthropic 填 <code>https://api.anthropic.com</code>；Gemini 填 <code>https://generativelanguage.googleapis.com</code>。</p>
  </div>
  <?php if ($models): ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>名称</th><th>归属</th><th>协议 / 模型</th><th>积分/次</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($models as $m): ?>
            <tr>
              <td>
                <b><?= e((string)$m['name']) ?></b>
                <?php if ((string)$m['note'] !== ''): ?><br><span class="tiny muted"><?= e((string)$m['note']) ?></span><?php endif; ?>
              </td>
              <td class="tiny"><?= (int)$m['owner_id'] === 0 ? '<span class="tag ok">系统</span>' : '<span class="tag">' . e((string)($m['owner_name'] ?? '用户#' . (int)$m['owner_id'])) . '</span>' ?></td>
              <td class="tiny">
                <?= e(ai_protocols()[(string)$m['protocol']] ?? (string)$m['protocol']) ?><br>
                <span class="mono"><?= e((string)$m['model_key']) ?></span><br>
                <span class="muted"><?= e((string)$m['base_url']) ?></span>
              </td>
              <td class="tiny"><?= (int)$m['credits'] > 0 ? (int)$m['credits'] : '不扣' ?></td>
              <td class="tiny"><?= (int)$m['enabled'] === 1 ? '<span class="tag ok">启用</span>' : '<span class="tag">停用</span>' ?></td>
              <td>
                <div class="ops">
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="model_test">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn sm" type="submit">测试</button>
                  </form>
                  <form method="post"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="model_diag">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn sm" type="submit" title="分段检查 DNS / TCP / TLS / HTTP，定位卡在哪一步">诊断</button>
                  </form>
                  <?php if ((int)$m['owner_id'] === 0): ?>
                    <form method="post" onsubmit="return confirm('删除这个系统模型？已有调用记录不受影响。')"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="model_delete">
                      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                      <button class="btn sm danger" type="submit">删除</button>
                    </form>
                  <?php endif; ?>
                </div>
                <?php if ((int)$m['owner_id'] === 0): ?>
                  <details style="margin-top:6px">
                    <summary class="tiny muted">编辑</summary>
                    <form method="post" class="form" style="margin-top:8px;min-width:280px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="model_save">
                      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                      <input type="hidden" name="enabled" value="<?= (int)$m['enabled'] ?>">
                      <label>名称<input type="text" name="name" value="<?= e((string)$m['name']) ?>" required></label>
                      <div class="row-form">
                        <label>协议
                          <select name="protocol">
                            <?php foreach (ai_protocols() as $k => $label): ?>
                              <option value="<?= e($k) ?>" <?= (string)$m['protocol'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label>积分/次<input type="number" name="credits" value="<?= (int)$m['credits'] ?>" min="0"></label>
                      </div>
                      <label>接口地址<input type="text" name="base_url" value="<?= e((string)$m['base_url']) ?>" required></label>
                      <label>模型名<input type="text" name="model_key" value="<?= e((string)$m['model_key']) ?>" required></label>
                      <label>API Key（留空不改）<input type="password" name="api_key" placeholder="••••••••" autocomplete="new-password"></label>
                      <div class="row-form">
                        <label>max_tokens<input type="number" name="max_tokens" value="<?= (int)$m['max_tokens'] ?>" min="64" max="32000"></label>
                        <label>temperature<input type="text" name="temperature" value="<?= e((string)$m['temperature']) ?>"></label>
                      </div>
                      <div class="row-form">
                        <label>超时（秒）<input type="number" name="timeout" value="<?= (int)$m['timeout'] ?>" min="10" max="300"></label>
                        <label>排序<input type="number" name="sort" value="<?= (int)$m['sort'] ?>"></label>
                      </div>
                      <label>备注<input type="text" name="note" value="<?= e((string)$m['note']) ?>"></label>
                      <div class="form-actions"><button class="btn primary" type="submit">保存</button></div>
                    </form>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="muted">还没有系统模型。下面加一个，用户才有东西可调。</p>
  <?php endif; ?>

  <details style="margin-top:14px">
    <summary><b>＋ 添加系统模型</b></summary>
    <form method="post" class="form" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="model_save">
      <input type="hidden" name="enabled" value="1">
      <div class="row-form">
        <label>名称<input type="text" name="name" placeholder="例如 DeepSeek V3" required></label>
        <label>协议
          <select name="protocol">
            <?php foreach (ai_protocols() as $k => $label): ?>
              <option value="<?= e($k) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>积分/次<input type="number" name="credits" value="30" min="0"></label>
      </div>
      <label>接口地址（Base URL）<input type="text" name="base_url" placeholder="https://api.deepseek.com/v1" required></label>
      <div class="row-form">
        <label>模型名<input type="text" name="model_key" placeholder="deepseek-chat" required></label>
        <label>API Key<input type="password" name="api_key" placeholder="sk-..." autocomplete="new-password" required></label>
      </div>
      <div class="row-form">
        <label>max_tokens<input type="number" name="max_tokens" value="2048" min="64" max="32000"></label>
        <label>temperature<input type="text" name="temperature" value="0.3"></label>
        <label>超时（秒）<input type="number" name="timeout" value="120" min="10" max="300"></label>
        <label>排序<input type="number" name="sort" value="0"></label>
      </div>
      <label>备注<input type="text" name="note" placeholder="给用户看的说明，例如「速度快，适合日常整理」"></label>
      <div class="form-actions"><button class="btn primary" type="submit">添加</button></div>
    </form>
  </details>
</section>

<section class="panel">
  <h2 class="panel-title">套餐（<?= count($plans) ?>）</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>名称</th><th>类型</th><th>价格</th><th>内容</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($plans as $p): ?>
          <?php $monthly = (string)$p['kind'] === 'monthly'; ?>
          <tr>
            <td><b><?= e((string)$p['name']) ?></b><?php if ((string)$p['note'] !== ''): ?><br><span class="tiny muted"><?= e((string)$p['note']) ?></span><?php endif; ?></td>
            <td class="tiny"><?= $monthly ? '月付' : '积分包' ?></td>
            <td class="tiny">¥<?= $yuan((int)$p['price_cents']) ?></td>
            <td class="tiny">
              <?= (int)$p['credits'] > 0 ? (int)$p['credits'] . ' 积分' : '无赠送' ?>
              <?php if ($monthly): ?> · <?= (int)$p['days'] ?> 天 · 槽位 <?= (int)$p['custom_slots'] ?> · 每日 <?= (int)$p['daily_calls'] > 0 ? (int)$p['daily_calls'] : '不限' ?><?php endif; ?>
              <?php if (!$monthly): ?> · 每日 <?= (int)$p['daily_calls'] > 0 ? (int)$p['daily_calls'] : '不限' ?><?php endif; ?>
            </td>
            <td class="tiny"><?= (int)$p['enabled'] === 1 ? '<span class="tag ok">上架</span>' : '<span class="tag">下架</span>' ?></td>
            <td>
              <div class="ops">
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="plan_toggle">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn sm" type="submit"><?= (int)$p['enabled'] === 1 ? '下架' : '上架' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('删除这个套餐？')"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="plan_delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn sm danger" type="submit">删除</button>
                </form>
              </div>
              <details style="margin-top:6px">
                <summary class="tiny muted">编辑</summary>
                <form method="post" class="form" style="margin-top:8px;min-width:300px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="plan_save">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="enabled" value="<?= (int)$p['enabled'] ?>">
                  <label>名称<input type="text" name="name" value="<?= e((string)$p['name']) ?>" required></label>
                  <div class="row-form">
                    <label>类型
                      <select name="kind">
                        <option value="credits" <?= $monthly ? '' : 'selected' ?>>积分包</option>
                        <option value="monthly" <?= $monthly ? 'selected' : '' ?>>月付</option>
                      </select>
                    </label>
                    <label>价格（元）<input type="text" name="price_yuan" value="<?= $yuan((int)$p['price_cents']) ?>"></label>
                  </div>
                  <div class="row-form">
                    <label>赠送积分<input type="number" name="credits" value="<?= (int)$p['credits'] ?>" min="0"></label>
                    <label>天数（月付）<input type="number" name="days" value="<?= (int)$p['days'] ?>" min="1"></label>
                  </div>
                  <div class="row-form">
                    <label>自带模型槽位<input type="number" name="custom_slots" value="<?= (int)$p['custom_slots'] ?>" min="0"></label>
                    <label>每日次数（0=不限）<input type="number" name="daily_calls" value="<?= (int)$p['daily_calls'] ?>" min="0"></label>
                  </div>
                  <label class="check"><input type="checkbox" name="all_models" value="1" <?= (int)$p['all_models'] === 1 ? 'checked' : '' ?>> 可用系统模型池</label>
                  <label>备注<input type="text" name="note" value="<?= e((string)$p['note']) ?>"></label>
                  <div class="form-actions"><button class="btn primary" type="submit">保存</button></div>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <details style="margin-top:14px">
    <summary><b>＋ 新建套餐</b></summary>
    <form method="post" class="form" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="plan_save">
      <input type="hidden" name="enabled" value="1">
      <div class="row-form">
        <label>名称<input type="text" name="name" placeholder="例如 月付 · 基础版" required></label>
        <label>类型
          <select name="kind">
            <option value="credits">积分包（一次性）</option>
            <option value="monthly">月付（订阅）</option>
          </select>
        </label>
        <label>价格（元）<input type="text" name="price_yuan" value="49.00"></label>
      </div>
      <div class="row-form">
        <label>赠送积分<input type="number" name="credits" value="2000" min="0"></label>
        <label>天数（月付）<input type="number" name="days" value="30" min="1"></label>
        <label>自带模型槽位<input type="number" name="custom_slots" value="3" min="0"></label>
        <label>每日次数（0=不限）<input type="number" name="daily_calls" value="300" min="0"></label>
      </div>
      <label class="check"><input type="checkbox" name="all_models" value="1" checked> 可用系统模型池（不勾就只能用自己的模型）</label>
      <label>备注<input type="text" name="note" placeholder="卖点说明，用户端会显示"></label>
      <div class="form-actions"><button class="btn primary" type="submit">创建</button></div>
    </form>
  </details>
</section>

<section class="panel">
  <details class="fold">
    <summary>兑换码 <span class="fold-note">购买入口 · 批量生成 · 已有码</span></summary>
    <div class="fold-body">
  <?php if ($newCodes): ?>
    <div class="hint" style="margin-bottom:12px">
      <p><b>刚生成的一批（批次 <?= e((string)$newCodes['batch']) ?>，关掉页面就看不到了，先复制走）：</b></p>
      <textarea rows="<?= min(14, count($newCodes['codes']) + 2) ?>" readonly style="width:100%;font-family:var(--mono);font-size:13px"><?= e(implode("\n", $newCodes['codes'])) ?></textarea>
    </div>
  <?php endif; ?>

  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="shop_save">
    <div class="row-form">
      <label>购买页名称
        <input type="text" name="shop_name" value="<?= e((string)$shopName) ?>" placeholder="例如：官方兑换码商城" maxlength="40">
      </label>
      <label>购买地址
        <input type="url" name="shop_url" value="<?= e((string)$shopUrl) ?>" placeholder="https://..." maxlength="240">
      </label>
    </div>
    <div class="form-actions"><button class="btn" type="submit">保存购买入口</button></div>
    <div class="field-hint">
      填了地址之后，用户的「账单」页的兑换码输入框下面会出现一个「去 xxx 购买」按钮（新窗口打开）。
      留空则不显示。这里只放链接，本站不代收兑换码的钱。
    </div>
  </form>

  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="code_gen">
    <div class="row-form">
      <label>类型
        <select name="kind">
          <option value="credits">加积分</option>
          <option value="plan">开套餐</option>
        </select>
      </label>
      <label>积分数量<input type="number" name="credits" value="1000" min="0"></label>
      <label>或套餐
        <select name="plan_id">
          <option value="0">（不指定）</option>
          <?php foreach ($plans as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>生成数量<input type="number" name="count" value="10" min="1" max="100"></label>
      <label>每码可用次数<input type="number" name="max_uses" value="1" min="1" max="1000"></label>
      <label>有效天数（0=永久）<input type="number" name="exp_days" value="0" min="0"></label>
    </div>
    <div class="form-actions"><button class="btn primary" type="submit">生成</button></div>
    <div class="field-hint">码格式 <code>KBXXXX-XXXX-XXXX</code>，同一用户对同一码只能兑一次。</div>
  </form>

  <?php if ($codes): ?>
    <div class="table-wrap" style="margin-top:14px">
      <table class="table">
        <thead><tr><th>兑换码</th><th>内容</th><th>用量</th><th>有效期</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($codes as $c): ?>
            <tr>
              <td class="mono tiny"><?= e((string)$c['code']) ?><br><span class="muted"><?= e((string)$c['batch']) ?></span></td>
              <td class="tiny">
                <?= (string)$c['kind'] === 'plan' ? '套餐 #' . (int)$c['plan_id'] : (int)$c['credits'] . ' 积分' ?>
              </td>
              <td class="tiny"><?= (int)$c['used_count'] ?> / <?= (int)$c['max_uses'] ?></td>
              <td class="tiny muted"><?= e((string)($c['expires_at'] ?? '永久')) ?></td>
              <td class="tiny"><?= (int)$c['enabled'] === 1 ? '<span class="tag ok">可用</span>' : '<span class="tag">停用</span>' ?></td>
              <td>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="code_toggle">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn sm" type="submit"><?= (int)$c['enabled'] === 1 ? '作废' : '启用' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
    </div>
  </details>
</section>

<section class="panel">
  <details class="fold">
    <summary>订单 <span class="fold-note">手动标记已支付 · 查看渠道与金额</span></summary>
    <div class="fold-body">
  <?php if (!$orders): ?>
    <p class="muted">还没有订单。用户下单后会出现在这里，付款后点「标记已支付」，系统自动发放套餐。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>单号</th><th>用户</th><th>套餐</th><th>金额</th><th>渠道</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td class="tiny mono"><?= e((string)$o['out_trade_no']) ?></td>
              <td class="tiny"><?= e((string)($o['username'] ?? ('#' . (int)$o['user_id']))) ?></td>
              <td class="tiny"><?= e((string)($o['plan_name'] ?? '—')) ?></td>
              <td class="tiny">¥<?= $yuan((int)$o['amount_cents']) ?></td>
              <td class="tiny"><?= e((string)$o['channel']) ?></td>
              <td class="tiny">
                <?php $st = (string)$o['status']; ?>
                <span class="tag <?= $st === 'paid' ? 'ok' : '' ?>"><?= e(['pending' => '待支付', 'paid' => '已支付', 'closed' => '已关闭', 'refunded' => '已退款'][$st] ?? $st) ?></span>
              </td>
              <td>
                <?php if ($st === 'pending'): ?>
                  <form method="post" onsubmit="return confirm('确认已收到这笔款？会立即给用户开通套餐/加积分。')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="order_pay">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button class="btn sm primary" type="submit">标记已支付</button>
                  </form>
                <?php else: ?>
                  <span class="muted tiny"><?= e((string)($o['paid_at'] ?? '')) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="hint" style="margin-top:10px">
    <p><b>支付接口预留：</b>订单表已有 <code>channel</code> / <code>transaction_id</code> 字段，接微信或支付宝时，
      只需在回调里按 `out_trade_no` 找到订单并调用同一段发货逻辑，不用改表结构。</p>
  </div>
    </div>
  </details>
</section>

<section class="panel">
  <details class="fold">
    <summary>手动调整积分 <span class="fold-note">线下付款 / 补偿，会写进用户积分流水</span></summary>
    <div class="fold-body">
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="credit_adjust">
    <div class="row-form">
      <label>用户 ID<input type="number" name="user_id" min="1" placeholder="用户列表里的 ID" required></label>
      <label>变动（正数加 / 负数减）<input type="number" name="delta" placeholder="例如 1000 或 -200" required></label>
      <label>备注<input type="text" name="note" placeholder="线下付款、补偿……"></label>
      <div style="align-self:flex-end"><button class="btn primary" type="submit">执行</button></div>
    </div>
    <div class="field-hint">扣减时余额不够会直接拒绝，不会扣成负数。每笔都会写进用户的积分流水。</div>
  </form>
    </div>
  </details>
</section>

<section class="panel">
  <details class="fold">
    <summary>提示词（可选覆盖） <span class="fold-note">只改人设；回答边界由系统固定追加，改这里关不掉</span></summary>
    <div class="fold-body">
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="prompt_save">
    <label>对话 system（只写人设与语气；「只答知识库相关」的边界由系统固定追加，覆盖不掉）
      <textarea name="prompt_chat" rows="3" placeholder="留空使用内置默认"><?= e((string)$prompts['chat']) ?></textarea>
    </label>
    <label>答案优化 system
      <textarea name="prompt_polish" rows="6" placeholder="留空使用内置默认"><?= e((string)$prompts['polish']) ?></textarea>
    </label>
    <label>文本转 QA system
      <textarea name="prompt_extract" rows="6" placeholder="留空使用内置默认"><?= e((string)$prompts['extract']) ?></textarea>
    </label>
    <label>AI 工作台 system（只写人设；输出格式、工具清单、回答边界由系统固定追加）
      <textarea name="prompt_agent" rows="4" placeholder="留空使用内置默认"><?= e((string)$prompts['agent']) ?></textarea>
    </label>
    <div class="form-actions"><button class="btn primary" type="submit">保存提示词</button></div>
    <div class="field-hint">
      提示词改坏了会直接影响所有用户的结果，改完自己先用「AI 助手」试一次。
      「只回答本知识库用法与用户可读的知识库内容、无关话题拒答、业务问题必须先检索」这条边界不由这里控制，
      改这里也关不掉——要放开就走源码。
    </div>
  </form>
    </div>
  </details>
</section>

<section class="panel">
  <details class="fold">
    <summary>最近调用 <span class="fold-note">最近 <?= count($calls) ?> 次 · 含失败原因</span></summary>
    <div class="fold-body">
  <?php if (!$calls): ?>
    <p class="muted">还没有调用记录。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>时间</th><th>用户</th><th>场景</th><th>模型</th><th>tokens</th><th>积分</th><th>耗时</th><th>状态</th></tr></thead>
        <tbody>
          <?php foreach ($calls as $c): ?>
            <tr>
              <td class="tiny muted"><?= e((string)$c['created_at']) ?></td>
              <td class="tiny"><?= e((string)($c['username'] ?? ('#' . (int)$c['user_id']))) ?></td>
              <td class="tiny"><span class="tag"><?= e((string)$c['action']) ?></span></td>
              <td class="tiny"><?= e((string)$c['model_name']) ?></td>
              <td class="tiny mono"><?= (int)$c['tokens_in'] ?>/<?= (int)$c['tokens_out'] ?></td>
              <td class="tiny"><?= (int)$c['credits'] ?></td>
              <td class="tiny"><?= (int)$c['latency_ms'] ?></td>
              <td class="tiny">
                <?php if ((string)$c['status'] === 'ok'): ?><span class="tag ok">成功</span>
                <?php else: ?><span class="tag" style="color:var(--err)" title="<?= e((string)$c['error']) ?>">失败</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
    </div>
  </details>
</section>
