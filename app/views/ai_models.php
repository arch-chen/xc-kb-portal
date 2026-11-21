<?php
/** @var array $rows 我的模型 @var array $ent 权益 */
$route = 'ai_models';
$canAdd = $ent['active'] && ($ent['custom_slots'] === 0 || $ent['custom_used'] < $ent['custom_slots']);
?>
<section class="page-head">
  <div>
    <h1 class="page-title">我的模型</h1>
    <p class="page-desc">填自己的模型接口，用你自己的额度。属于月付套餐功能，调用不扣本站积分。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('ai')) ?>">AI 助手</a>
    <a class="btn" href="<?= e(u('recharge')) ?>">充值与订单</a>
  </div>
</section>

<?= nav_bill_tabs('ai_models') ?>

<?php if (!$ent['active']): ?>
  <section class="panel">
    <div class="hint">
      <p><b>还没开通月付套餐。</b>自带模型属于套餐功能，开通后可以添加
        <?= (int)$ent['custom_slots'] > 0 ? (int)$ent['custom_slots'] . ' 个' : '（数量由套餐决定）' ?>自己的模型接口。</p>
      <p><a class="btn primary" href="<?= e(u('recharge')) ?>">去看套餐</a></p>
    </div>
  </section>
<?php endif; ?>

<section class="panel">
  <h2 class="panel-title">已添加的模型（<?= count($rows) ?> / <?= $ent['active'] ? (int)$ent['custom_slots'] : 0 ?>）</h2>
  <?php if (!$rows): ?>
    <p class="muted">还没有。下面表单里填一个就能用，例如 DeepSeek / Kimi / 通义 / 豆包，或本地 Ollama。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>名称</th><th>协议</th><th>模型名</th><th>密钥</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $m): ?>
            <tr>
              <td><?= e((string)$m['name']) ?><?php if ((string)$m['note'] !== ''): ?><br><span class="tiny muted"><?= e((string)$m['note']) ?></span><?php endif; ?></td>
              <td class="tiny"><?= e(ai_protocols()[(string)$m['protocol']] ?? (string)$m['protocol']) ?></td>
              <td class="tiny mono"><?= e((string)$m['model_key']) ?><br><span class="muted"><?= e((string)$m['base_url']) ?></span></td>
              <td class="tiny mono"><?= e((string)($m['key_mask'] ?? '')) ?: '<span class="muted">—</span>' ?></td>
              <td>
                <div class="ops">
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn sm" type="submit">测试</button>
                  </form>
                  <form method="post" onsubmit="return confirm('删除这个模型？')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn sm danger" type="submit">删除</button>
                  </form>
                </div>
              </td>
            </tr>
            <tr>
              <td colspan="5" style="background:var(--panel-2)">
                <details>
                  <summary class="tiny muted">修改这个模型</summary>
                  <form method="post" class="form" style="margin-top:10px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <div class="row-form">
                      <label>名称<input type="text" name="name" value="<?= e((string)$m['name']) ?>"></label>
                      <label>协议
                        <select name="protocol">
                          <?php foreach (ai_protocols() as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= (string)$m['protocol'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </div>
                    <div class="row-form">
                      <label>接口地址<input type="text" name="base_url" value="<?= e((string)$m['base_url']) ?>"></label>
                      <label>模型名<input type="text" name="model_key" value="<?= e((string)$m['model_key']) ?>"></label>
                    </div>
                    <div class="row-form">
                      <label>API Key（留空不改）<input type="password" name="api_key" placeholder="••••••••" autocomplete="new-password"></label>
                      <label>max_tokens<input type="number" name="max_tokens" value="<?= (int)$m['max_tokens'] ?>" min="64" max="32000"></label>
                    </div>
                    <div class="row-form">
                      <label>temperature<input type="text" name="temperature" value="<?= e((string)$m['temperature']) ?>"></label>
                      <label>超时（秒）<input type="number" name="timeout" value="<?= (int)$m['timeout'] ?>" min="10" max="300"></label>
                    </div>
                    <label>备注<input type="text" name="note" value="<?= e((string)$m['note']) ?>"></label>
                    <div class="form-actions"><button class="btn primary" type="submit">保存</button></div>
                  </form>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="panel-title">添加模型</h2>
  <?php if (!$canAdd): ?>
    <div class="hint">
      <p><?= $ent['active'] ? '套餐的模型槽位已用完，先删一个或升级套餐。' : '需要先开通月付套餐。' ?></p>
    </div>
  <?php else: ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <div class="row-form">
        <label>名称<input type="text" name="name" placeholder="例如 我的 DeepSeek" required></label>
        <label>协议
          <select name="protocol">
            <?php foreach (ai_protocols() as $k => $label): ?>
              <option value="<?= e($k) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="row-form">
        <label>接口地址（Base URL）<input type="text" name="base_url" placeholder="https://api.deepseek.com/v1" required></label>
        <label>模型名<input type="text" name="model_key" placeholder="deepseek-chat" required></label>
      </div>
      <label>API Key<input type="password" name="api_key" placeholder="sk-..." autocomplete="new-password" required></label>
      <div class="row-form">
        <label>max_tokens<input type="number" name="max_tokens" value="2048" min="64" max="32000"></label>
        <label>temperature<input type="text" name="temperature" value="0.3"></label>
        <label>超时（秒）<input type="number" name="timeout" value="120" min="10" max="300"></label>
      </div>
      <label>备注<input type="text" name="note" placeholder="给自己看的说明"></label>
      <div class="form-actions"><button class="btn primary" type="submit">添加</button></div>
    </form>

    <div class="hint" style="margin-top:14px">
      <p><b>三种协议怎么填：</b></p>
      <p>· <b>OpenAI 兼容</b>：地址填到 <code>/v1</code> 为止（如 <code>https://api.deepseek.com/v1</code>），系统自动接 <code>/chat/completions</code>。<br>
         · <b>Anthropic</b>：地址填 <code>https://api.anthropic.com</code>，模型名如 <code>claude-sonnet-4-20250514</code>。<br>
         · <b>Gemini</b>：地址填 <code>https://generativelanguage.googleapis.com</code>，模型名如 <code>gemini-2.0-flash</code>，Key 直接填 API Key。</p>
      <p>本机 Ollama 填 <code>http://127.0.0.1:11434/v1</code>，Key 随便填一个非空值。</p>
    </div>
  <?php endif; ?>
</section>
