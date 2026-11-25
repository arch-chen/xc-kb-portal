<?php /** @var array $rows @var array $groups @var array $perms @var ?string $newKey */
$base = base_url();
$apiEntry = $base . '/api.php?path=';
$mcpUrl = $base . '/mcp.php';
$openApiUrl = $base . '/openapi.php';

// 长篇解释统一收进感叹号气泡，页面只留标题、输入框和按钮。
$tipBase = '<p><b>两者不能混用。</b>客户端（<code>agent_client.py</code> 的 <code>KB(url, key)</code>）'
    . '只填 Base URL，它会自己在后面拼 <code>/api.php?path=/v1/…</code>；'
    . '如果把「完整入口」填进去，请求会变成 <code>/api.php/api.php</code>，多数服务器直接 404。</p>'
    . '<p>完整请求长这样：<br><code>' . e($apiEntry) . 'v1/search&amp;q=库存清零</code></p>'
    . '<p class="muted">地址按当前访问的域名自动生成。若你是通过内网 IP 打开后台，复制到的也是内网地址；'
    . '要让外部 Agent 能连，请在 <code>config.php</code> 的 <code>app.url</code> 里固定域名。</p>';

$tipMcp = '<p><b>ChatGPT 连接器 → 添加自定义 MCP 服务器</b>（不是「创建插件」那两项，那两项给无服务器的静态小工具用）：</p>'
    . '<ol>'
    . '<li>地址填下方<b>「带 Key 的地址」</b>（<code>?key=</code> 那一行），不要填光秃秃的 <code>/mcp.php</code>；</li>'
    . '<li>身份验证选<b>「无需身份验证」</b>——Key 已经在 URL 里了，再选 OAuth 会因为本站没有授权服务器而报「无法发现 OAuth 设置」。</li>'
    . '</ol>'
    . '<p class="muted">ChatGPT 的「Bearer / API Key」选项只接受它自己走 OAuth 换来的 Token，不认手工粘贴的静态 Key，'
    . '所以对 ChatGPT 只能走 URL 带 Key 这条路。代价是 Key 会出现在访问日志和浏览器历史里，'
    . '给第三方用就单独建一把只读 Key，别拿全能 Key 去连。</p>'
    . '<p><b>Claude / Cursor / Cherry Studio：</b>这些支持自定义 Header，用干净的 MCP 地址 + '
    . '<code>Authorization: Bearer</code>，见下方可折叠片段，Key 不进 URL 更安全。</p>'
    . '<p><b>Dify / n8n / 自写 Agent：</b>走 REST，不需要 MCP。</p>'
    . '<p><b>自定义 GPT（Actions）：</b>Action → Import from URL 填 OpenAPI 地址 → '
    . '认证选 API Key → Auth Type 选 Bearer → 粘 Key。官方已宣布自定义 GPT 逐步退役，这条只适合过渡用。</p>'
    . '<p class="muted"><b>注意权限边界。</b>一个 Key 只能读写它绑定的那个分组；只勾了「读取」的 Key，'
    . '连上去也写不进去。给第三方 AI 用之前想清楚：这个分组的内容会随对话一起发给对方。</p>';

$tipKey = '<p>Key 只显示一次明文，之后库里留加密副本，可随时点「显示」还原。</p>'
    . '<p><b>读 / 写</b>：读=搜索与查看，写=新增或修改条目。写权限需要你自己拥有该分组的写权限，'
    . '否则勾了也不生效。</p>'
    . '<p><b>有效期</b>留空即永久。到期或吊销后，用它的 Agent 会立刻失效。</p>';
?>

<section class="page-head">
  <div>
    <h1 class="page-title">我的 API Key</h1>
    <p class="page-desc">给 Agent 用的访问凭证。每个 Key 只对一个分组生效，读和写可以分开授权。<?=
      tip_text('对外提供的是 /api.php（REST）与 /mcp.php（MCP）两个入口，同一把 Key 通用。', '', '页面说明') ?></p>
  </div>
</section>

<?php if ($newKey): ?>
  <div class="newkey">
    <div class="newkey-title">Key 已生成</div>
    <div class="newkey-row">
      <code id="newKeyText"><?= e($newKey) ?></code>
      <button class="btn" type="button" id="copyKey">复制</button>
    </div>
    <div class="newkey-hint">只显示这一次，关掉就看不到了。之后可在下方列表点「显示」取回。</div>
  </div>
<?php endif; ?>

<section class="panel endpoint">
  <?= panel_title('接口地址', $tipBase, 'Agent 对接用这个') ?>

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

  <div class="ep-check-row">
    <button type="button" class="btn sm" id="apiCheck" data-base="<?= e($base) ?>">测试连通</button>
    <span class="ep-check muted" id="apiCheckOut">会请求一次 /v1/health</span>
  </div>
</section>

<section class="panel endpoint">
  <?= panel_title('接进 ChatGPT', $tipMcp, 'MCP') ?>

  <div class="ep-row">
    <div class="ep-label">MCP 服务器地址<span class="ep-sub">Claude、Cursor、Cherry Studio 等支持自定义 Header 的客户端</span></div>
    <div class="ep-box">
      <code id="epMcp"><?= e($mcpUrl) ?></code>
      <button type="button" class="btn sm" data-copy="<?= e($mcpUrl) ?>">复制</button>
    </div>
  </div>

  <div class="ep-row">
    <div class="ep-label">带 Key 的地址<span class="ep-sub">ChatGPT 连接器填这一行，Key 直接拼在 URL 里</span></div>
    <div class="ep-box">
      <code id="epMcpKey"><?= e($mcpUrl) ?>?key=kb_你的Key</code>
      <span class="ep-check muted">下方每个 Key 都有「复制 MCP 地址」按钮</span>
    </div>
  </div>

  <div class="ep-row">
    <div class="ep-label">OpenAPI 描述<span class="ep-sub">自定义 GPT 的 Action 里 Import from URL</span></div>
    <div class="ep-box">
      <code id="epOpenapi"><?= e($openApiUrl) ?></code>
      <button type="button" class="btn sm" data-copy="<?= e($openApiUrl) ?>">复制</button>
    </div>
  </div>

  <div class="ep-warn">
    <b>ChatGPT 里身份验证必须选「无需身份验证」。</b>Key 已经写在 URL 的 <code>?key=</code> 里，
    再选 OAuth 会因为本站没有授权服务器而报「无法发现 OAuth 设置」。
  </div>

  <div class="ep-check-row">
    <a class="btn sm" href="<?= e(u('docs')) ?>" target="_blank" rel="noopener">开发文档（可直接发给对方）</a>
    <span class="ep-check muted">含各客户端的完整配置步骤</span>
  </div>

  <details class="fold ep-fold">
    <summary>Claude / Cursor 配置片段</summary>
    <div class="fold-body">
      <p class="tiny muted">粘进 <code>mcp.json</code>，把 <code>kb_你的Key</code> 换成列表里复制的明文：</p>
      <pre><code>{"mcpServers":{"kb":{"url":"<?= e($mcpUrl) ?>","headers":{"Authorization":"Bearer kb_你的Key"}}}}</code></pre>
      <button type="button" class="btn sm"
              data-copy="{&quot;mcpServers&quot;:{&quot;kb&quot;:{&quot;url&quot;:&quot;<?= e($mcpUrl) ?>&quot;,&quot;headers&quot;:{&quot;Authorization&quot;:&quot;Bearer kb_你的Key&quot;}}}}">复制片段</button>
    </div>
  </details>
</section>

<div class="two-col">
  <section class="panel">
    <?= panel_title('新建 Key', $tipKey) ?>
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
    <?= panel_title('调用示例', '<p>三行 curl 覆盖「我是谁 / 搜索 / 新增」三个最常用动作，Python 客户端只需两行。</p>'
        . '<p class="muted">把 <code>你的Key</code> 换成下方列表里点「复制」拿到的明文。</p>') ?>
    <details class="fold ep-fold">
      <summary>curl 与 Python 代码</summary>
      <div class="fold-body">
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
        <p class="tiny muted">Python 客户端：</p>
        <pre><code>from agent_client import KB
kb = KB("<?= e($base) ?>", "kb_xxxxxxxx")
print(kb.search("库存清零", limit=3))</code></pre>
      </div>
    </details>
  </section>
</div>

<section class="panel">
  <?= panel_title('已创建的 Key', '<p><b>最后使用</b>为空的 Key 说明配好之后还没有任何请求打过来。</p>'
      . '<p class="muted">旧版本创建的 Key 只存了哈希，明文无法还原，重建一把即可。</p>') ?>
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
                     data-base="<?= e($base) ?>" data-mcp="<?= e($mcpUrl) ?>">
                  <code class="key-text"><?= e($r['key_mask']) ?></code>
                  <?php if ((int)$r['revoked'] === 0): ?>
                    <button type="button" class="link" data-key-act="reveal"
                            data-url="<?= e(u('key_reveal', ['id' => (int)$r['id']])) ?>">显示</button>
                    <button type="button" class="link" data-key-act="copy"
                            data-url="<?= e(u('key_reveal', ['id' => (int)$r['id']])) ?>">复制</button>
                    <button type="button" class="link" data-key-act="copymcp"
                            data-url="<?= e(u('key_reveal', ['id' => (int)$r['id']])) ?>"
                            title="复制带 Key 的 MCP 地址，ChatGPT 连接器直接粘这一行">复制 MCP 地址</button>
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
