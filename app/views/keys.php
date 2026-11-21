<?php /** @var array $rows @var array $groups @var array $perms @var ?string $newKey */
$base = base_url();
$apiEntry = $base . '/api.php?path=';
$mcpUrl = $base . '/mcp.php';
$openApiUrl = $base . '/openapi.php';
?>
<section class="page-head">
  <div>
    <h1 class="page-title">我的 API Key</h1>
    <p class="page-desc">给 Agent 用的访问凭证。每个 Key 只对一个分组生效，读和写可以分开授权。</p>
  </div>
</section>

<?php if ($newKey): ?>
  <div class="newkey">
    <div class="newkey-title">Key 已生成</div>
    <div class="newkey-row">
      <code id="newKeyText"><?= e($newKey) ?></code>
      <button class="btn" type="button" id="copyKey">复制</button>
    </div>
    <div class="newkey-hint">
      接口地址：<code><?= e($base) ?></code>　（下面「接口地址」一栏有完整说明）
    </div>
  </div>
<?php endif; ?>

<section class="panel endpoint">
  <h2 class="panel-title">接口地址 <span class="tag">Agent 对接用这个</span></h2>

  <div class="ep-row">
    <div class="ep-label">Base URL<span class="ep-sub">Python / JS 客户端、SDK 填这一行</span></div>
    <div class="ep-box">
      <code id="epBase"><?= e($base) ?></code>
      <button type="button" class="btn sm" data-copy="<?= e($base) ?>">复制</button>
    </div>
  </div>

  <div class="ep-row">
    <div class="ep-label">完整入口<span class="ep-sub">curl、Postman、HTTP 工具直接用</span></div>
    <div class="ep-box">
      <code id="epEntry"><?= e($apiEntry) ?></code>
      <button type="button" class="btn sm" data-copy="<?= e($apiEntry) ?>">复制</button>
    </div>
  </div>

  <div class="ep-note">
    <p><b>两者不能混用。</b>客户端（<code>agent_client.py</code> 的 <code>KB(url, key)</code>）只填 Base URL，
    它会自己在后面拼 <code>/api.php?path=/v1/…</code>；如果这里填成带 <code>/api.php</code> 的完整入口，
    请求就会变成 <code>/api.php/api.php</code>，多数服务器直接返回 404。</p>
    <p>完整请求长这样：<code><?= e($base) ?>/api.php?path=/v1/search&amp;q=库存清零&amp;limit=5</code></p>
    <p class="muted tiny">地址按当前访问的域名自动生成。如果你通过内网 IP 打开后台，复制到的也是内网地址；
    要让外部 Agent 能用，请在配置里固定域名（<code>config.php</code> 的 <code>app.url</code>）。</p>
  </div>

  <div class="ep-check-row">
    <button type="button" class="btn sm" id="apiCheck" data-base="<?= e($base) ?>">测试连通</button>
    <span class="ep-check muted" id="apiCheckOut">会请求一次 /v1/health，确认 PHP 与接口是否正常</span>
  </div>
</section>

<section class="panel endpoint">
  <h2 class="panel-title">接进 ChatGPT <span class="tag">MCP</span></h2>

  <div class="ep-row">
    <div class="ep-label">MCP 服务器地址<span class="ep-sub">ChatGPT 插件、Claude 自定义连接器填这一行</span></div>
    <div class="ep-box">
      <code id="epMcp"><?= e($mcpUrl) ?></code>
      <button type="button" class="btn sm" data-copy="<?= e($mcpUrl) ?>">复制</button>
    </div>
  </div>

  <div class="ep-row">
    <div class="ep-label">OpenAPI 描述<span class="ep-sub">自定义 GPT 的 Action 里「Import from URL」</span></div>
    <div class="ep-box">
      <code id="epOpenapi"><?= e($openApiUrl) ?></code>
      <button type="button" class="btn sm" data-copy="<?= e($openApiUrl) ?>">复制</button>
    </div>
  </div>

  <div class="ep-note">
    <p><b>ChatGPT 连接器 / 插件：</b>添加自定义连接器 → 地址填上面的 MCP 地址 → 鉴权选 Bearer，粘一个
      <code>kb_</code> 开头的 Key。如果只有「OAuth / 无鉴权」两个选项，说明当前套餐或客户端版本还没开放
      Token 方式，走下面的 Actions 方案。</p>
    <p><b>自定义 GPT（Actions）：</b>Action → Import from URL 填 OpenAPI 地址 → 认证选 API Key →
      Auth Type 选 Bearer → 粘 Key。官方已宣布自定义 GPT 逐步退役，这条只适合过渡用。</p>
    <p><b>Claude / Cursor / 其他 MCP 客户端：</b></p>
    <pre><code>{"mcpServers":{"kb":{"url":"<?= e($mcpUrl) ?>","headers":{"Authorization":"Bearer kb_你的Key"}}}}</code></pre>
    <p class="muted tiny">一个 Key 只能读写它绑定的那个分组；只勾了「读取」的 Key，连上去也写不进去。
      给第三方 AI 用之前想清楚：这个分组的内容会随对话一起发给对方。</p>
    <p><a class="btn sm" href="<?= e(u('docs')) ?>" target="_blank" rel="noopener">完整开发文档（可直接发给对方）</a></p>
  </div>
</section>

<div class="two-col">
  <section class="panel">
    <h2 class="panel-title">新建 Key</h2>
    <?php if (!$groups): ?>
      <div class="empty">你还没有可访问的分组，请联系管理员授权。</div>
    <?php else: ?>
      <form method="post" action="<?= e(u('keys')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label>名称
          <input type="text" name="name" placeholder="例如：客服 Agent" maxlength="60">
        </label>
        <label>绑定分组
          <select name="group_id" id="keyGroup" required>
            <?php foreach ($groups as $g): $p = $perms[(int)$g['id']] ?? ['read' => false, 'write' => false]; ?>
              <option value="<?= (int)$g['id'] ?>"
                      data-read="<?= $p['read'] ? 1 : 0 ?>"
                      data-write="<?= $p['write'] ? 1 : 0 ?>">
                <?= e($g['name']) ?><?= $p['write'] ? '（可读写）' : '（只读）' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="checks">
          <label class="check"><input type="checkbox" name="can_read" value="1" id="canRead" checked> 读取</label>
          <label class="check"><input type="checkbox" name="can_write" value="1" id="canWrite"> 写入</label>
        </div>
        <div class="hint" id="scopeHint">写权限需要你本身拥有该分组的写权限，否则不会生效。</div>
        <label>有效期（留空为永久）
          <input type="date" name="expires_at">
        </label>
        <button class="btn primary" type="submit">生成 Key</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2 class="panel-title">调用示例</h2>
    <div class="prose small">
      <p>把 <code>你的Key</code> 换成下面列表里点「复制」拿到的明文：</p>
      <pre><code># 我是谁、有哪些权限
curl -H "Authorization: Bearer 你的Key" \
  "<?= e($base) ?>/api.php?path=/v1/me"

# 搜索本分组
curl -H "Authorization: Bearer 你的Key" \
  "<?= e($base) ?>/api.php?path=/v1/search&q=关键词&limit=5"

# 新增一条问答（需要写权限）
curl -X POST -H "Authorization: Bearer 你的Key" \
  -H "Content-Type: application/json" \
  -d '{"kind":"qa","question":"问题","body":"答案"}' \
  "<?= e($base) ?>/api.php?path=/v1/entries"</code></pre>
      <p class="muted">Python 客户端只需两行：</p>
      <pre><code>from agent_client import KB
kb = KB("<?= e($base) ?>", "kb_xxxxxxxx")
print(kb.search("库存清零", limit=3))</code></pre>
    </div>
  </section>
</div>

<section class="panel">
  <h2 class="panel-title">已创建的 Key</h2>
  <?php if (!$rows): ?>
    <div class="empty">还没有创建过 Key。</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>名称</th><th>Key</th><th>分组</th><th>权限</th><th>状态</th><th>最后使用</th><th>创建</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $expired = $r['expires_at'] && strtotime((string)$r['expires_at']) < time();
            $state = (int)$r['revoked'] === 1 ? '已吊销' : ($expired ? '已过期' : '有效');
        ?>
          <tr>
            <td><?= e($r['name']) ?><div class="mono tiny muted"><?= e($r['key_prefix']) ?>…</div></td>
            <td>
              <?php if ($r['key_mask'] === ''): ?>
                <span class="tiny muted" title="旧版本只存了哈希，明文无法还原">旧 Key，重建后才能查看</span>
              <?php else: ?>
                <div class="key-cell" data-key-id="<?= (int)$r['id'] ?>" data-mask="<?= e($r['key_mask']) ?>"
                     data-base="<?= e($base) ?>">
                  <code class="key-text"><?= e($r['key_mask']) ?></code>
                  <?php if ((int)$r['revoked'] === 0): ?>
                    <button type="button" class="link" data-key-act="reveal"
                            data-url="<?= e(u('key_reveal', ['id' => (int)$r['id']])) ?>">显示</button>
                    <button type="button" class="link" data-key-act="copy"
                            data-url="<?= e(u('key_reveal', ['id' => (int)$r['id']])) ?>">复制</button>
                    <button type="button" class="link" data-key-act="copyenv"
                            data-url="<?= e(u('key_reveal', ['id' => (int)$r['id']])) ?>"
                            title="复制 KB_URL 与 KB_KEY 两行，直接贴进 Agent 配置">复制接入配置</button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= e($r['group_name'] ?: '—') ?></td>
            <td>
              <?= (int)$r['can_read'] ? '读' : '' ?><?= (int)$r['can_read'] && (int)$r['can_write'] ? ' + ' : '' ?><?= (int)$r['can_write'] ? '写' : '' ?>
            </td>
            <td><span class="pill <?= $state === '有效' ? 'ok' : 'off' ?>"><?= $state ?></span></td>
            <td class="tiny"><?= e($r['last_used_at'] ? human_date($r['last_used_at']) : '未使用') ?></td>
            <td class="tiny"><?= e(substr((string)$r['created_at'], 0, 10)) ?></td>
            <td class="right">
              <?php if ((int)$r['revoked'] === 0): ?>
                <form method="post" action="<?= e(u('keys')) ?>" onsubmit="return confirm('吊销后使用该 Key 的 Agent 会立刻失效，确定？')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="link danger" type="submit">吊销</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
