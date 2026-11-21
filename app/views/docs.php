<?php
/**
 * 公开开发者文档（免登录）。
 * 面向「其他 Agent / 平台接入」，不是普通用户的帮助页。
 * @var string $mcpEnabled
 */
$base  = base_url();
$api   = $base . '/api.php?path=';
$mcp   = $base . '/mcp.php';
$oas   = $base . '/openapi.php';
$llms  = $base . '/llms.txt';
$u     = current_user();
$ver   = APP_VERSION;

$nav = [
    'overview'  => '概览',
    'start'     => '快速开始',
    'auth'      => '鉴权与权限',
    'format'    => '响应与错误码',
    'rest'      => 'REST 接口',
    'write'     => '写入语义',
    'sync'      => '增量同步',
    'mcp'       => 'MCP 接入',
    'openapi'   => 'OpenAPI',
    'ai'        => 'AI 接口',
    'examples'  => '代码示例',
    'limits'    => '限制与边界',
    'faq'       => '排查手册',
    'changelog' => '变更记录',
];

/** 代码块 + 复制按钮 */
$cb = function (string $code): void {
    echo '<div class="codeblock"><button type="button" class="code-copy" data-copy="'
        . e($code) . '">复制</button><pre><code>' . e($code) . '</code></pre></div>';
};

/** 接口卡片 */
$ep = function (string $method, string $path, string $desc, string $anchor, array $params = [], string $example = '') use ($cb): void {
    $cls = ['GET' => 'get', 'POST' => 'post', 'PUT' => 'put', 'PATCH' => 'put', 'DELETE' => 'del'][$method] ?? 'get';
    echo '<div class="ep-card" id="' . e($anchor) . '">';
    echo '<div class="ep-head"><span class="method ' . $cls . '">' . e($method) . '</span>'
        . '<span class="ep-path">' . e($path) . '</span></div>';
    echo '<div class="ep-desc">' . $desc . '</div>';
    if ($params) {
        echo '<div class="table-wrap"><table class="table"><thead><tr>'
            . '<th style="width:150px">参数</th><th style="width:90px">类型</th>'
            . '<th style="width:70px">必填</th><th>说明</th></tr></thead><tbody>';
        foreach ($params as $p) {
            [$name, $type, $req, $note] = $p + [null, 'string', false, ''];
            echo '<tr><td><code>' . e((string)$name) . '</code></td><td class="muted">' . e((string)$type) . '</td>'
                . '<td>' . ($req ? '<span class="req">必填</span>' : '<span class="opt">可选</span>') . '</td>'
                . '<td>' . $note . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if ($example !== '') {
        $cb($example);
    }
    echo '</div>';
};
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title('开发文档')) ?></title>
<meta name="description" content="<?= e(app_name()) ?> 对外开放接口文档：REST API、MCP、OpenAPI，供其他 Agent 与平台接入。">
<?= site_favicon_tag() ?>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('docs.css')) ?>?v=<?= e(asset_ver('docs.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
</head>
<body class="docs-body">

<header class="docs-top">
  <div class="docs-top-in">
    <a class="brand" href="<?= e(u()) ?>">
      <?= site_brand_mark() ?>
      <span><?= e(app_name()) ?></span>
    </a>
    <span class="docs-top-tag">开发文档</span>
    <button type="button" class="docs-menu-btn" id="docsMenuBtn">目录</button>
    <div class="docs-top-acts">
      <span class="ver">v<?= e($ver) ?></span>
      <button type="button" class="link-btn" id="themeBtn"><span id="themeIco">◐</span> <span id="themeLabel">跟随系统</span></button>
      <?php if ($u): ?>
        <a class="btn sm" href="<?= e(u('keys')) ?>">我的 API Key</a>
        <a class="btn sm" href="<?= e(u()) ?>">进入控制台</a>
      <?php else: ?>
        <a class="btn sm" href="<?= e(u('login')) ?>">登录</a>
        <?php if (register_allowed()): ?>
          <a class="btn sm primary" href="<?= e(u('register')) ?>">注册</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="docs-shell">
  <nav class="docs-nav" id="docsNav">
    <div class="docs-nav-title">目录</div>
    <?php foreach ($nav as $id => $label): ?>
      <a href="#<?= e($id) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <div class="docs-nav-title" style="margin-top:14px">机器可读</div>
    <a class="docs-nav-sub" href="<?= e($oas) ?>">OpenAPI 描述</a>
    <a class="docs-nav-sub" href="<?= e($llms) ?>">llms.txt</a>
    <a class="docs-nav-sub" href="<?= e($api) ?>/v1/health">健康检查</a>
  </nav>

  <main class="docs-main">

    <!-- ============ 概览 ============ -->
    <section id="overview">
      <div class="docs-hero">
        <h1><?= e(app_name()) ?> · 开放接口</h1>
        <p class="lead">这是一套给「机器」用的知识库接口：把微信群沉淀的问答、整理好的文档，以只读检索或幂等写入的方式，开放给 ChatGPT、Claude、Cursor、Dify、n8n，以及你自己写的 Agent。</p>
        <div class="docs-facts">
          <div class="docs-fact"><span class="k">Base URL</span><span class="v"><?= e($base) ?></span></div>
          <div class="docs-fact"><span class="k">REST 入口</span><span class="v">/api.php?path=/v1</span></div>
          <div class="docs-fact"><span class="k">MCP 端点</span><span class="v">/mcp.php</span></div>
          <div class="docs-fact"><span class="k">鉴权</span><span class="v">Bearer &lt;API Key&gt;</span></div>
        </div>
      </div>

      <h3>三种接入方式，按你的平台选</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:150px">方式</th><th style="width:190px">入口</th><th>适合谁</th></tr></thead>
          <tbody>
            <tr>
              <td><b>MCP</b></td>
              <td><code><?= e($mcp) ?></code></td>
              <td>支持 Model Context Protocol 的客户端（Claude、Cursor、Cherry Studio、自建 Agent 框架）。工具已定义好，模型自己决定什么时候查、什么时候写。</td>
            </tr>
            <tr>
              <td><b>REST API</b></td>
              <td><code><?= e($api) ?>/v1/…</code></td>
              <td>Dify / n8n / Coze / 自写脚本。拿到 JSON 自己编排，控制力最强。</td>
            </tr>
            <tr>
              <td><b>OpenAPI</b></td>
              <td><code><?= e($oas) ?></code></td>
              <td>自定义 GPT 的 Actions、以及其他「导入 OpenAPI 描述」就能接的平台。</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="note">
        <b>能力边界先说清：</b>一个 API Key 只能访问<b>一个</b>分组（知识库），读、写分开授权。
        请求体里写 <code>group_id</code> 不会生效——分组由 Key 决定，管理员的 Key 也不能跨组。
      </div>

      <h3>能力清单</h3>
      <ul>
        <li><b>检索</b>：中文全文检索，返回命中条目 + 高亮片段 + 相关度分数。</li>
        <li><b>读取</b>：按 id 取全文、分页遍历、查看历史版本、读「文档」及其收录的问答。</li>
        <li><b>写入</b>：按问题指纹去重（幂等），答案变了才升版本，旧值留档。</li>
        <li><b>同步</b>：按时间戳增量拉变更（含删除标记），适合做本地缓存/向量库。</li>
      </ul>
    </section>

    <!-- ============ 快速开始 ============ -->
    <section id="start">
      <h2>快速开始</h2>
      <ol>
        <li>
          <b>创建 Key。</b>登录后进「我的 API Key」→ 选分组 → 勾读/写 → 创建。
          Key 只在创建时显示一次，形如 <code>kb_xxxxxxxx</code>。
          <?php if (!$u): ?><a href="<?= e(u('login')) ?>">先登录</a><?php endif; ?>
        </li>
        <li>
          <b>自检连通。</b>健康检查不需要 Key，用来确认地址和 PHP 是否正常：
          <?php $cb('curl -s "' . $api . '/v1/health"'); ?>
          正常返回：<code>{"ok":true,"data":{"service":"…","version":"<?= e($ver) ?>"}}</code>
        </li>
        <li>
          <b>带 Key 查一条。</b>
          <?php $cb('export KB_URL=' . $base . "\nexport KB_KEY=kb_你的Key\n\ncurl -s -H \"Authorization: Bearer \$KB_KEY\" \\\n  \"\$KB_URL/api.php?path=/v1/search&q=库存清零&limit=5\""); ?>
        </li>
        <li>
          <b>接到平台上。</b>MCP 客户端填 MCP 地址，Dify/n8n 用 REST，GPT Actions 导入 OpenAPI。详见下面各节。
        </li>
      </ol>

      <div class="warn">
        <b>地址别拼错。</b>Base URL 是站点根（<code><?= e($base) ?></code>），完整入口才是
        <code><?= e($base) ?>/api.php?path=</code>。
        有些客户端要求你填「完整接口地址」，它自己会在后面接 <code>/v1/search</code>；
        如果两边都带 <code>/api.php</code>，请求会变成 <code>/api.php/api.php/…</code>，直接 404。
      </div>

      <p class="tiny muted">地址按当前访问域名生成。如果用内网 IP 打开这一页，复制到的是内网地址；
      要让外部平台访问，请在 <code>config.php</code> 里固定 <code>app.url</code>。</p>
    </section>

    <!-- ============ 鉴权 ============ -->
    <section id="auth">
      <h2>鉴权与权限</h2>
      <p>所有 <code>/v1/*</code> 接口（除 <code>/v1/health</code>）都要求带 Key，两种写法等价：</p>
      <?php $cb("Authorization: Bearer kb_你的Key\nX-Api-Key: kb_你的Key"); ?>
      <p>Key 的有效权限 = <b>Key 自身勾选的读写</b> ∩ <b>所属用户在该分组的权限</b>。
      用户被移出分组、或 Key 被吊销/过期，接口立刻失效（401/403），不需要重启服务。</p>

      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:220px">情况</th><th style="width:110px">HTTP</th><th>返回</th></tr></thead>
          <tbody>
            <tr><td>没带 Key / Key 不存在 / 已吊销 / 过期</td><td>401</td><td><code>unauthorized</code></td></tr>
            <tr><td>Key 还在，但用户已失去分组权限</td><td>403</td><td><code>forbidden</code></td></tr>
            <tr><td>只读 Key 调写接口（POST/PUT/DELETE）</td><td>403</td><td><code>readonly</code></td></tr>
            <tr><td>条目/文档存在，但属于别的分组</td><td>403</td><td><code>forbidden</code></td></tr>
          </tbody>
        </table>
      </div>

      <div class="note">
        不要把 Key 写进前端代码或公开仓库。服务端调用放环境变量（<code>KB_KEY</code>）里；
        需要给不同团队隔离，就一个分组建一个 Key，别共用一个全能 Key。
      </div>
    </section>

    <!-- ============ 响应与错误码 ============ -->
    <section id="format">
      <h2>响应格式与错误码</h2>
      <p>成功：</p>
      <?php $cb('{
  "ok": true,
  "data": [ ... ],
  "meta": { "total": 12, "limit": 5, "offset": 0, "query": "库存清零" }
}'); ?>
      <p>失败：</p>
      <?php $cb('{
  "ok": false,
  "error": { "code": "unauthorized", "message": "API Key 缺失、无效、已吊销或已过期" }
}'); ?>
      <p><code>data</code> 是数组还是对象取决于接口；<code>meta</code> 只在分页/同步类接口出现。
      编码固定 UTF-8，时间格式固定 <code>YYYY-MM-DD HH:MM:SS</code>（服务器本地时区）。</p>

      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:150px">error.code</th><th style="width:80px">HTTP</th><th>含义与处理建议</th></tr></thead>
          <tbody>
            <tr><td><code>unauthorized</code></td><td>401</td><td>Key 无效。检查是否漏了 <code>Bearer </code> 前缀、Key 是否被吊销或过期。</td></tr>
            <tr><td><code>forbidden</code></td><td>403</td><td>无权限或跨组。确认 Key 绑定的分组与目标条目一致。</td></tr>
            <tr><td><code>readonly</code></td><td>403</td><td>该 Key 只读。需要写入请重建一个勾了「写入」的 Key。</td></tr>
            <tr><td><code>bad_request</code></td><td>400</td><td>参数缺失或格式不对（如缺 <code>q</code>、批量超过 200 条）。</td></tr>
            <tr><td><code>not_found</code></td><td>404</td><td>条目不存在、已软删，或接口路径拼错。</td></tr>
            <tr><td><code>server_error</code></td><td>500</td><td>服务端异常，<code>message</code> 里有原因；写入批次已整体回滚。</td></tr>
          </tbody>
        </table>
      </div>
      <p class="tiny muted">所有接口都支持 <code>OPTIONS</code> 预检（返回 204），
      响应头带 <code>Access-Control-Allow-Origin: *</code>，浏览器端可以直接调用（但仍不建议在浏览器里放 Key）。</p>
    </section>

    <!-- ============ REST ============ -->
    <section id="rest">
      <h2>REST 接口</h2>
      <p>以下路径都省略了前缀 <code><?= e($base) ?>/api.php?path=</code>。例如
      <code>GET /v1/search</code> 的完整地址是
      <code><?= e($base) ?>/api.php?path=/v1/search&amp;q=…</code>。</p>

      <h4>条目对象（entry）</h4>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:130px">字段</th><th style="width:90px">类型</th><th>说明</th></tr></thead>
          <tbody>
            <tr><td><code>id</code></td><td>int</td><td>条目 id，全局唯一，写入/查询都用它。</td></tr>
            <tr><td><code>group_id</code></td><td>int</td><td>所属分组，等于 Key 绑定的分组。</td></tr>
            <tr><td><code>kind</code></td><td>string</td><td><code>qa</code> 问答 / <code>doc</code> 文档。</td></tr>
            <tr><td><code>title</code></td><td>string</td><td>标题，可为空。</td></tr>
            <tr><td><code>question</code></td><td>string</td><td>问题原文，文档类型为空串。</td></tr>
            <tr><td><code>body</code></td><td>string</td><td>正文，Markdown。</td></tr>
            <tr><td><code>source</code></td><td>string</td><td>来源标注，如「微信群」「MCP」「api」。</td></tr>
            <tr><td><code>source_date</code></td><td>string</td><td>原始内容日期，可为空。</td></tr>
            <tr><td><code>version</code></td><td>int</td><td>版本号，从 1 开始；答案变更时 +1。</td></tr>
            <tr><td><code>created_at</code> / <code>updated_at</code></td><td>string</td><td>创建/更新时间。</td></tr>
          </tbody>
        </table>
      </div>

      <h3>读接口</h3>
      <?php
      $ep('GET', '/v1/health', '健康检查。<b>不需要 Key</b>，用来确认地址与 PHP 是否正常。', 'ep-health', [], 'curl -s "' . $api . '/v1/health"');

      $ep('GET', '/v1/me', '当前 Key 的身份：用户、Key 名称与前缀、绑定的分组、读写权限、条目数、服务版本。接入自检第一步调它。', 'ep-me',
          [], 'curl -s -H "Authorization: Bearer $KB_KEY" "' . $api . '/v1/me"');

      $ep('GET', '/v1/groups', 'Key 绑定的分组（只返回一个，不给越界想象空间）。', 'ep-groups',
          [], 'curl -s -H "Authorization: Bearer $KB_KEY" "' . $api . '/v1/groups"');

      $ep('GET', '/v1/search', '中文全文检索。命中结果额外带 <code>score</code>（相关度）和 <code>snippet</code>（高亮片段）。问题长或含特殊字符时用 POST 更稳。', 'ep-search', [
          ['q', 'string', true, '检索词，或一整句自然语言问题。GET 用 <code>q</code>，POST 也可以用 <code>query</code>。'],
          ['limit', 'int', false, '返回条数，1~50，默认 10。'],
          ['offset', 'int', false, '偏移量，默认 0，配合 <code>meta.total</code> 翻页。'],
          ['kind', 'string', false, '只看 <code>qa</code> 或只看 <code>doc</code>；不填则都查。'],
      ], 'curl -s -H "Authorization: Bearer $KB_KEY" \\' . "\n" . '  "' . $api . '/v1/search?q=库存清零&limit=5"');

      $ep('POST', '/v1/search', '同上的 JSON 版本，长问题推荐。', 'ep-search-post', [
          ['q / query', 'string', true, '检索词。'],
          ['limit', 'int', false, '1~50，默认 10。'],
          ['offset', 'int', false, '偏移量。'],
          ['kind', 'string', false, 'qa / doc。'],
      ], 'curl -s -X POST -H "Authorization: Bearer $KB_KEY" -H "Content-Type: application/json" \\' . "\n"
          . '  -d \'{"q":"退货地址写哪里","limit":5}\' "' . $api . '/v1/search"');

      $ep('GET', '/v1/entries', '分页遍历条目，按更新时间倒序。适合「导出目录」「逐条检查」。', 'ep-entries', [
          ['limit', 'int', false, '1~100，默认 20。'],
          ['offset', 'int', false, '偏移量，默认 0。'],
          ['kind', 'string', false, 'qa / doc。'],
      ], 'curl -s -H "Authorization: Bearer $KB_KEY" \\' . "\n" . '  "' . $api . '/v1/entries?limit=20&offset=0"');

      $ep('GET', '/v1/entries/{id}', '按 id 取一条全文。若 <code>kind=doc</code>，额外返回 <code>links</code>：该文档收录的问答。', 'ep-entry', [], '');

      $ep('GET', '/v1/entries/{id}/versions', '版本历史，包含当前版本（<code>is_current: true</code>）。答案被改过几次、每次改前是什么，都在这里。', 'ep-versions', [], '');

      $ep('GET', '/v1/docs/{id}', '文档专用只读视图：正文 + <code>items</code>（收录的问答，含人工备注 <code>note</code>）。', 'ep-docs', [], '');

      $ep('GET', '/v1/export', '全量分页导出，按 <code>id</code> 升序。给 Agent 建本地缓存/向量库用：循环拿 <code>next_after</code> 直到 <code>has_more=false</code>。', 'ep-export', [
          ['after', 'int', false, '从这个 id 之后开始，默认 0。'],
          ['limit', 'int', false, '1~500，默认 200。'],
          ['kind', 'string', false, 'qa / doc。'],
      ], '');

      $ep('GET', '/v1/changes', '增量同步：<code>since</code> 之后被改动的条目，含删除标记。详见「增量同步」一节。', 'ep-changes', [
          ['since', 'string', false, '上次返回的 <code>next_since</code>；不填表示从最早开始。'],
          ['limit', 'int', false, '1~500，默认 200。'],
      ], '');
      ?>

      <h3>写接口</h3>
      <p>写接口需要 Key 具备写权限，否则返回 <code>readonly</code>。所有写入都会记审计日志。</p>
      <?php
      $ep('POST', '/v1/entries', '新增或更新条目。<b>默认幂等</b>：按问题指纹去重，同一个问题不会重复插入。支持单条，也支持 <code>{"items":[…]}</code> 批量（≤200 条，整批一个事务）。', 'ep-write', [
          ['mode', 'string', false, '<code>upsert</code>（默认，按问题去重）或 <code>create</code>（强制新增）。'],
          ['items', 'array', false, '批量写入。不传则把请求体自身当成一条。'],
          ['kind', 'string', false, '<code>qa</code>（默认）或 <code>doc</code>。'],
          ['question', 'string', false, '问题原文（qa 建议必填）。<code>title/question/body</code> 至少填一项。'],
          ['body', 'string', false, '答案正文，Markdown（qa 建议必填）。'],
          ['title', 'string', false, '标题。'],
          ['source', 'string', false, '来源标注，默认 <code>api</code>。'],
          ['source_date', 'string', false, '原始日期，如 <code>2026-11-20</code>。'],
          ['id', 'int', false, '<b>仅 doc</b>：带 id 表示更新已有文档；不带则新增。'],
          ['entry_ids', 'int[]', false, '<b>仅 doc</b>：要收录的问答 id 列表。'],
      ], 'curl -s -X POST -H "Authorization: Bearer $KB_KEY" -H "Content-Type: application/json" \\' . "\n"
          . '  -d \'{"items":[{"question":"退货地址写哪里？","body":"…","source":"客服群"}]}\' \\' . "\n"
          . '  "' . $api . '/v1/entries"');

      $ep('PUT', '/v1/entries/{id}', '整体更新一条。<b>每次成功都会升版本</b>并快照旧值；想避免无意义升版本，先比对内容再决定要不要调。', 'ep-update', [
          ['title', 'string', false, '新标题。'],
          ['question', 'string', false, '新问题。会重算问题指纹。'],
          ['body', 'string', false, '新正文。会重算答案指纹。'],
          ['source / source_date', 'string', false, '来源信息。'],
          ['kind', 'string', false, 'qa / doc。'],
      ], '');

      $ep('DELETE', '/v1/entries/{id}', '软删除（<code>status=0</code>），检索立刻不再命中；<code>/v1/changes</code> 会带 <code>deleted:true</code> 通知下游。', 'ep-delete', [], '');
      ?>

      <h3>文件与附件</h3>
      <p>文件可以走对象存储（本地磁盘 / OSS / COS / 七牛 / 又拍云 / R2 多副本），但<b>对外只有一个地址</b>：
      <code>/v1/attachments/{id}/download</code>，照样是 Key 鉴权、照样限制在这个分组内。
      正文里要显示图片，把返回的 <code>markdown</code> 字段（形如 <code>![名字](下载地址)</code>）填进 <code>body</code> 即可。</p>
      <?php
      $ep('GET', '/v1/attachments', '列出这个分组的文件。返回 <code>download</code>（下载地址）与 <code>markdown</code>（可直接塞进正文的引用）。', 'ep-att-list', [
          ['entry_id', 'int', false, '只看绑定到某条目的文件。'],
          ['limit', 'int', false, '1~100，默认 50。'],
          ['offset', 'int', false, '偏移量。'],
      ], 'curl -s -H "Authorization: Bearer $KB_KEY" \\' . "\n" . '  "' . $api . '/v1/attachments?limit=20"');

      $ep('POST', '/v1/attachments', '上传文件。<b>两种传法</b>：multipart（字段 <code>file</code> 或 <code>files[]</code>），
      或 JSON（<code>{"name":"a.png","data":"&lt;base64&gt;"}</code>）。白名单扩展名，单文件上限由站点设置（默认 20MB）。
      同一个分组内内容相同的文件会自动复用，不会存两份。', 'ep-att-upload', [
          ['file / files[]', 'file', false, 'multipart 上传时的字段名。'],
          ['name', 'string', false, 'JSON 模式下的文件名（含扩展名，决定类型）。'],
          ['data', 'string', false, 'JSON 模式下的 base64 内容，支持 <code>data:</code> 前缀。'],
          ['entry_id', 'int', false, '可选，绑定到某条目的条目 id。'],
      ], 'curl -s -X POST -H "Authorization: Bearer $KB_KEY" \\' . "\n"
          . '  -F "file=@./photo.png" "' . $api . '/v1/attachments"');

      $ep('GET', '/v1/attachments/{id}', '单个文件的元信息：名称、类型、大小、绑定条目、下载地址。', 'ep-att-get', [], '');

      $ep('GET', '/v1/attachments/{id}/download', '取文件字节。返回真实 <code>Content-Type</code>，可直接给模型看或用 <code>&lt;img src&gt;</code>。
      <b>不需要额外的签名 URL</b>，带上同一个 Key 就能取。', 'ep-att-dl', [], 'curl -s -H "Authorization: Bearer $KB_KEY" \\' . "\n"
          . '  -o photo.png "' . $api . '/v1/attachments/12/download"');
      ?>

      <h4>检索响应示例</h4>
      <?php $cb('{
  "ok": true,
  "data": [
    {
      "id": 1831,
      "group_id": 1,
      "kind": "qa",
      "title": "库存清零怎么处理",
      "question": "库存被清零了怎么办？",
      "body": "先在后台看是否触发了…",
      "source": "微信群",
      "source_date": "2026-11-18",
      "version": 3,
      "updated_at": "2026-11-18 21:40:12",
      "created_at": "2026-10-30 09:12:00",
      "score": 8.42,
      "snippet": "库存被清零…先在后台看是否触发了…"
    }
  ],
  "meta": { "total": 1, "limit": 5, "offset": 0, "query": "库存清零" }
}'); ?>
    </section>

    <!-- ============ 写入语义 ============ -->
    <section id="write">
      <h2>写入语义：幂等，不重复</h2>
      <p>知识库最怕越写越乱。所以写的默认行为不是「插入」，而是「对齐」：</p>
      <ul>
        <li>每条问答有两个指纹：<code>qfp</code>（问题，归一化空白后计算）和 <code>afp</code>（答案）。</li>
        <li>同分组内 <code>qfp</code> 已存在 → 走更新，不新增。</li>
        <li>答案指纹没变 → <code>skipped</code>，版本号不动。</li>
        <li>答案变了 → <code>updated</code>，<code>version+1</code>，旧值写入 <code>entry_versions</code>。</li>
      </ul>

      <h4>返回结构</h4>
      <?php $cb('{
  "ok": true,
  "data": {
    "ids": [1832],
    "created": 1,
    "updated": 0,
    "skipped": 0,
    "results": [
      { "index": 0, "action": "created", "id": 1832, "version": 1, "changed": true }
    ]
  },
  "errors": []
}'); ?>
      <p><code>action</code> 只会是 <code>created</code> / <code>updated</code> / <code>skipped</code>。
      批量里某几条失败不影响其它条（<code>errors</code> 带 <code>index</code> 和原因），但数据库层面仍是一个事务。</p>

      <div class="warn">
        <b>文档（doc）不幂等。</b>文档没有「问题」可以去重，所以更新已有文档<b>必须带 <code>id</code></b>；
        不带 id 每次都是新增一份副本。这也是同步脚本最容易踩的坑。
      </div>

      <h4>什么时候用 create</h4>
      <p>同一个问题确实需要保留两条独立记录（例如不同门店的同类问题），才用 <code>mode=create</code>。
      一般场景不要用，否则知识库会出现大量近似重复条目。</p>
    </section>

    <!-- ============ 增量同步 ============ -->
    <section id="sync">
      <h2>增量同步</h2>
      <p>把知识库同步到本地缓存、向量库或其他系统时，不要每次全量拉，用 <code>/v1/changes</code> 走增量：</p>
      <ol>
        <li>第一次调用不传 <code>since</code>，拿到一批变更。</li>
        <li>记下 <code>meta.next_since</code>，下次作为 <code>since</code> 传回来。</li>
        <li><code>meta.has_more=true</code> 说明还有，用新的 <code>next_since</code> 继续拉，直到 false。</li>
        <li>收到 <code>deleted:true</code> 的条目，从你的本地索引里删掉。</li>
      </ol>
      <?php $cb('{
  "ok": true,
  "data": [
    { "id": 1831, "kind": "qa", "title": "库存清零怎么处理",
      "question": "库存被清零了怎么办？", "version": 3,
      "source_date": "2026-11-18", "updated_at": "2026-11-18 21:40:12", "deleted": false }
  ],
  "meta": {
    "since": "2026-11-18 00:00:00",
    "next_since": "2026-11-18 21:40:12",
    "limit": 200,
    "has_more": false,
    "server_time": "2026-11-20 17:20:00"
  }
}'); ?>
      <p class="tiny muted">比较用的是严格的 <code>updated_at &gt; since</code>，所以同一秒内的多条变更不会被跳过。
      需要「现在几点」做基准时用 <code>meta.server_time</code>，别用本地时钟。</p>
    </section>

    <!-- ============ MCP ============ -->
    <section id="mcp">
      <h2>MCP 接入</h2>
      <p>MCP 端点：<code><?= e($mcp) ?></code>。协议是 JSON-RPC 2.0，采用 <b>无状态</b> Streamable HTTP：
      每个 POST 一条 JSON，直接回 JSON，<b>不开 SSE、不发 session id</b>。所以任何反向代理、负载均衡都不会出问题。</p>

      <h4>支持的 JSON-RPC 方法</h4>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:180px">方法</th><th>说明</th></tr></thead>
          <tbody>
            <tr><td><code>initialize</code></td><td>握手，回协议版本、服务信息、给模型的 instructions。</td></tr>
            <tr><td><code>ping</code></td><td>心跳。</td></tr>
            <tr><td><code>tools/list</code></td><td>列出 5 个工具。</td></tr>
            <tr><td><code>tools/call</code></td><td>调用工具。</td></tr>
            <tr><td><code>resources/list</code> / <code>prompts/list</code></td><td>返回空数组（本服务不提供资源与提示模板）。</td></tr>
          </tbody>
        </table>
      </div>

      <h4>工具</h4>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:150px">工具</th><th style="width:70px">权限</th><th>作用与参数</th></tr></thead>
          <tbody>
            <tr><td><code>kb_search</code></td><td>读</td><td>中文全文检索。<code>query</code> 必填；<code>limit</code> 1~20（默认 8）；<code>kind</code> 可选 qa/doc。返回带 <code>[id:数字]</code> 的 Markdown。</td></tr>
            <tr><td><code>kb_get</code></td><td>读</td><td>按 <code>id</code> 取全文（文档会附关联问答列表）。</td></tr>
            <tr><td><code>kb_changes</code></td><td>读</td><td>增量变更。<code>since</code> 用上次的 <code>next_since</code>；<code>limit</code> 1~200（默认 100）。</td></tr>
            <tr><td><code>kb_info</code></td><td>读</td><td>当前连的是哪个库、多少条、有没有写权限。</td></tr>
            <tr><td><code>kb_upsert_qa</code></td><td>写</td><td>存结论。<code>question</code>、<code>body</code> 必填，<code>title</code>、<code>source</code> 可选。按问题去重。</td></tr>
          </tbody>
        </table>
      </div>

      <div class="note">
        工具内部失败（没权限、id 不存在、参数为空）返回的是 <code>{"content":[…],"isError":true}</code>，
        不是 JSON-RPC error。这样模型能读懂原因并转述给用户，而不是整个连接报错。
      </div>

      <h4>客户端配置</h4>
      <p>Claude Desktop / Cursor / Cherry Studio 等 <code>mcpServers</code> 形式的配置：</p>
      <?php $cb('{
  "mcpServers": {
    "wayfair-kb": {
      "type": "http",
      "url": "' . $mcp . '",
      "headers": { "Authorization": "Bearer kb_你的Key" }
    }
  }
}'); ?>
      <p>命令行手测（确认握手与工具调用都通）：</p>
      <?php $cb('curl -s -X POST "' . $mcp . '" \\' . "\n"
          . '  -H "Authorization: Bearer $KB_KEY" \\' . "\n"
          . '  -H "Content-Type: application/json" \\' . "\n"
          . '  -H "Accept: application/json, text/event-stream" \\' . "\n"
          . '  -d \'{"jsonrpc":"2.0","id":1,"method":"tools/call",'
          . '"params":{"name":"kb_search","arguments":{"query":"库存清零","limit":3}}}\''); ?>

      <h4>ChatGPT 说明</h4>
      <p>ChatGPT 的「连接器」不同版本对自定义 MCP 的鉴权支持不一样：多数版本只接受 OAuth 或不带鉴权，
      <b>静态 Bearer 要看账号套餐</b>。两条可行路径：</p>
      <ul>
        <li>能填自定义 Header 的客户端：直接按上面的 JSON 配。</li>
        <li>只有 Actions 的（自定义 GPT）：导入 <code><?= e($oas) ?></code>，认证选 API Key → Bearer，粘 Key。</li>
      </ul>
      <p class="tiny muted">服务端对未带凭证的请求返回 <code>401</code> + <code>WWW-Authenticate: Bearer</code>，
      支持标准 Bearer 的客户端会提示补 Key。<b>本站不提供 OAuth 授权服务器</b>，
      所以只认静态 Token：如果客户端只给「OAuth / 无鉴权」两个选项，就走 OpenAPI + Actions 那条路。</p>

      <h4>MCP 状态码</h4>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:120px">code</th><th style="width:90px">HTTP</th><th>含义</th></tr></thead>
          <tbody>
            <tr><td><code>-32700</code></td><td>200</td><td>请求体不是合法 JSON。</td></tr>
            <tr><td><code>-32600</code></td><td>200</td><td>无效请求（缺 <code>method</code>）。</td></tr>
            <tr><td><code>-32601</code></td><td>200</td><td>不支持的方法。</td></tr>
            <tr><td><code>-32001</code></td><td>401</td><td>未授权，附带 <code>WWW-Authenticate</code>。</td></tr>
            <tr><td><code>-32003</code></td><td>200</td><td>Key 已失去该分组权限。</td></tr>
            <tr><td><code>-32004</code></td><td>200</td><td>管理员关闭了 MCP 接入。</td></tr>
            <tr><td><code>-32000</code></td><td>405 / 503</td><td>用了 GET（本服务只收 POST），或系统尚未安装。</td></tr>
          </tbody>
        </table>
      </div>
      <p class="tiny muted">当前状态：MCP 接入<?= $mcpEnabled ? '已开启' : '<b>已关闭</b>（需管理员在「系统与升级」里打开）' ?>。
      通知类消息（<code>notifications/*</code>，无 <code>id</code>）返回 <code>202</code> 且无响应体。</p>
    </section>

    <!-- ============ OpenAPI ============ -->
    <section id="openapi">
      <h2>OpenAPI 描述</h2>
      <p><code><?= e($oas) ?></code> 动态返回 OpenAPI 3.0.3 文档，字段与 REST 接口一一对应，
      <code>operationId</code>、<code>summary</code> 都按「什么时候该用这个接口」写，模型选工具时更容易选对。</p>
      <ul>
        <li>默认 <code>servers.url</code> = <code><?= e($base) ?>/api.php?path=/v1</code>，<b>不依赖伪静态</b>，虚拟主机可直接用。</li>
        <li>加了 <code>?mode=path</code> 则输出 <code><?= e($base) ?>/api/v1</code> 形式，需要服务器已配 rewrite。</li>
      </ul>
      <?php $cb('curl -s "' . $oas . '" | head -40'); ?>
      <p>导入方式：自定义 GPT → Actions → Import from URL；其他平台同理。
      认证选 API Key，位置 <code>Authorization</code>，值前缀 <code>Bearer </code>。</p>
    </section>

    <!-- ============ 代码示例 ============ -->
    <section id="examples">
      <h2>代码示例</h2>
      <h3>Python（零依赖，标准库）</h3>
      <?php $cb('import json, os, urllib.parse, urllib.request

BASE = os.environ["KB_URL"].rstrip("/")   # 例如 ' . $base . '
KEY  = os.environ["KB_KEY"]

def api(path, params=None, body=None, method="GET"):
    url = BASE + "/api.php?path=" + path
    if params:
        url += "&" + urllib.parse.urlencode(params)
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", "Bearer " + KEY)
    if data:
        req.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode("utf-8"))

# 检索
res = api("/v1/search", {"q": "库存清零", "limit": 5})
for it in res["data"]:
    print(it["id"], it["title"] or it["question"], it["snippet"])

# 写入（按问题去重）
api("/v1/entries", method="POST", body={"items": [
    {"question": "退货地址写哪里？", "body": "……", "source": "客服群"}
]})'); ?>

      <h3>Node.js / 浏览器（fetch）</h3>
      <?php $cb('const BASE = process.env.KB_URL.replace(/\/$/, "");
const KEY = process.env.KB_KEY;

async function call(path, opt = {}) {
  const r = await fetch(`${BASE}/api.php?path=${path}`, {
    ...opt,
    headers: {
      Authorization: `Bearer ${KEY}`,
      "Content-Type": "application/json",
      ...(opt.headers || {}),
    },
  });
  const data = await r.json();
  if (!data.ok) throw new Error(`${data.error.code}: ${data.error.message}`);
  return data;
}

const hits = await call("/v1/search?q=" + encodeURIComponent("库存清零"));
console.log(hits.meta.total, hits.data[0]?.snippet);'); ?>

      <h3>增量同步（Python 骨架）</h3>
      <?php $cb('since = ""            # 第一次留空
while True:
    q = {"limit": 200}
    if since:
        q["since"] = since
    d = api("/v1/changes", q)
    for it in d["data"]:
        if it["deleted"]:
            drop_local(it["id"])
        else:
            upsert_local(api("/v1/entries/%d" % it["id"])["data"])
    since = d["meta"]["next_since"]
    if not d["meta"]["has_more"]:
        break'); ?>

      <h3>把本页喂给模型</h3>
      <p><code><?= e($llms) ?></code> 是给 Agent 读的纯文本索引（llms.txt 约定），
      只讲「有哪些入口、怎么鉴权、有哪些工具」，适合直接塞进上下文，不占太多 token。</p>
      <?php $cb('curl -s "' . $llms . '"'); ?>
    </section>

    <!-- ============ AI ============ -->
    <section id="ai">
      <h2>AI 接口（1.8.0）</h2>
      <p>站点可以把配置好的大模型开放给 Agent 调用，<b>按次扣该用户的 AI 积分</b>。鉴权与上面的接口完全一样（Bearer Key），
        所以同一个 Key 既能查知识库，也能调 AI。</p>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:220px">接口</th><th style="width:70px">方法</th><th>说明</th></tr></thead>
          <tbody>
            <tr><td><code>/v1/ai/me</code></td><td>GET</td><td>余额、套餐、今日用量、自有模型槽位。调 AI 前先用它判断能不能调。</td></tr>
            <tr><td><code>/v1/ai/models</code></td><td>GET</td><td>可用模型列表，含每个模型每次扣多少积分。</td></tr>
            <tr><td><code>/v1/ai/chat</code></td><td>POST</td><td>通用对话。参数：<code>text</code>、<code>model_id</code>（可选）、<code>history</code>（可选）。</td></tr>
            <tr><td><code>/v1/ai/polish</code></td><td>POST</td><td>优化一条答案。参数：<code>entry_id</code> 或 <code>text</code>。只返回建议，不会自动写回。</td></tr>
            <tr><td><code>/v1/ai/extract</code></td><td>POST</td><td>群聊文本 → QA 草稿。参数：<code>text</code>、<code>max</code>（1~20）。返回 <code>items</code> 数组。</td></tr>
            <tr><td><code>/v1/ai/redeem</code></td><td>POST</td><td>兑换码充值。参数：<code>code</code>。</td></tr>
          </tbody>
        </table>
      </div>
      <p><b>积分不足</b>返回 HTTP <code>402</code>，错误码 <code>insufficient</code>；模型鉴权失败返回 <code>502</code>（<code>auth</code>），
        上游限流返回 <code>429</code>（<code>rate</code>）。<b>调用失败会自动退回积分</b>，失败记录在 <code>/v1/ai/me</code> 的用量里不计次数。</p>
      <?php $cb('curl -s -X POST "' . $api . '/ai/chat" \\
  -H "Authorization: Bearer kb_xxx" \\
  -H "Content-Type: application/json" \\
  -d \'{"text":"Wayfair 包装容易踩哪些坑？", "model_id": 1}\''); ?>
      <p class="tiny muted">MCP 也有两个对应工具：<code>kb_ai_ask</code>、<code>kb_ai_polish</code>，同样按次计费。</p>

      <h3>积分从哪里来</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:170px">渠道</th><th style="width:120px">能否用 API</th><th>说明</th></tr></thead>
          <tbody>
            <tr><td>在线支付</td><td>否（网页操作）</td><td>用户在站内「账户设置 → 账单」选套餐付款，回调到账。第三方无法代付。</td></tr>
            <tr><td>兑换码</td><td>可以</td><td><code>POST /v1/ai/redeem</code>，参数 <code>code</code>。码由管理员批量生成，可限次数与有效期。</td></tr>
            <tr><td>管理员调整</td><td>否</td><td>后台「AI 设置 → 积分调整」，可正可负，会写流水。</td></tr>
            <tr><td>月付套餐</td><td>否</td><td>开通后按套餐给积分、解锁自带模型槽位与每日次数。</td></tr>
          </tbody>
        </table>
      </div>
      <p class="tiny muted">余额与流水随时可用 <code>/v1/ai/me</code> 查；扣费口径是「按次固定积分」，调用前预扣、失败全额退回。</p>
    </section>

    <!-- ============ 限制 ============ -->
    <section id="limits">
      <h2>限制与边界</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:230px">项目</th><th style="width:160px">限制</th><th>说明</th></tr></thead>
          <tbody>
            <tr><td>一个 Key 的分组</td><td>1 个</td><td>跨组一律 403；管理员的 Key 也不跨组。</td></tr>
            <tr><td>检索 <code>limit</code></td><td>1~50（默认 10）</td><td>超过自动截断到上限，不报错。</td></tr>
            <tr><td>遍历 <code>/v1/entries</code></td><td>1~100（默认 20）</td><td>需要更多请翻页。</td></tr>
            <tr><td><code>/v1/changes</code></td><td>1~500（默认 200）</td><td>循环到 <code>has_more=false</code>。</td></tr>
            <tr><td><code>/v1/export</code></td><td>1~500（默认 200）</td><td>按 id 升序，用 <code>next_after</code> 翻页。</td></tr>
            <tr><td>批量写入</td><td>≤200 条/次</td><td>整批一个事务，失败整体回滚。</td></tr>
            <tr><td><code>body</code> 大小</td><td>受 PHP 限制</td><td>默认 <code>post_max_size</code>；超长内容建议拆条或调大配置。</td></tr>
            <tr><td>MCP 检索</td><td>1~20（默认 8）</td><td>给模型控制上下文长度用。</td></tr>
          </tbody>
        </table>
      </div>
      <p class="tiny muted">检索是中文二字组倒排 + 覆盖率过滤，短词、单字、英文缩写命中率会低一些；
      用整句自然语言描述往往比只输两个字更准。</p>
    </section>

    <!-- ============ FAQ ============ -->
    <section id="faq">
      <h2>排查手册</h2>

      <h3>返回 HTML 而不是 JSON</h3>
      <p>说明请求被站点首页或错误页接管了。依次检查：地址是否拼成 <code>/api.php/api.php</code>；
      是否漏了 <code>?path=</code>；<code>config.php</code> 的 <code>app.url</code> 是否与真实域名一致；
      服务器是否把 <code>api.php</code> 也重写进了 index.php。</p>

      <h3>401 一直不过</h3>
      <ul>
        <li>Header 必须是 <code>Authorization: Bearer kb_xxx</code>，<b>Bearer 后面一个空格</b>。</li>
        <li>Key 只在创建时显示一次；丢了就重新建一个，旧的可吊销。</li>
        <li>检查 Key 是否设了过期时间。</li>
      </ul>

      <h3>403 readonly</h3>
      <p>Key 只勾了读。写操作要重建一个勾了「写入」的 Key，并且该用户在这个分组本身要有写权限。</p>

      <h3>404 接口不存在</h3>
      <p><code>?path=</code> 后面必须以 <code>/v1/</code> 开头，例如 <code>/v1/search</code>。
      带 id 的路径不要有多余斜杠。</p>

      <h3>MCP 连不上</h3>
      <ul>
        <li>405：客户端用 GET 想开 SSE 流。本服务只接受 POST，换「Streamable HTTP / 无状态」模式。</li>
        <li>-32004：管理员在后台关了 MCP 接入。</li>
        <li>返回 <code>401</code> 且带 <code>WWW-Authenticate</code>：Key 没带上或无效。</li>
      </ul>

      <h3>服务端脚本拉不到数据</h3>
      <p>如果服务器走代理，可能被 <code>HTTP_PROXY</code> 影响。先用
      <code>curl --noproxy '*'</code> 直连试一次，能通就是代理配置问题，不是接口问题。</p>
    </section>

    <!-- ============ 变更记录 ============ -->
    <section id="changelog">
      <h2>变更记录</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th style="width:90px">版本</th><th>变更</th></tr></thead>
          <tbody>
            <tr><td><code>2.4.0</code></td><td>站点身份可自定义 + 侧栏订阅入口。<b>① 网站基础信息</b>（管理后台 → 系统与升级 → 网站基础信息）：以前只能改「站点名称」一项，副标题、图标、页脚、备案号全散在模板里硬编码（登录页甚至写死「XC知识库系统」，改了站点名压根不变）。现在一处可改 12 项：站点名称、副标题、站点简介、站点图标（logo）、浏览器小图标（favicon）、登录页公告、页脚版权、ICP 备案号 + 链接、公安备案号 + 链接、联系方式。保存后全站立即生效——左侧栏品牌、浏览器标签、登录/注册/找回密码页、开发文档页、支付结果页、所有外发邮件里的站点名都跟着变。<b>图标</b>可上传（png/jpg/gif/webp，favicon 另加 ico）或填外链；存在 <code>storage/branding/</code>、经 <code>index.php?r=branding</code> 输出（<code>storage/</code> 在 nginx 上被 deny，直链会 404），<b>明确不收 svg</b>——svg 直链打开会执行脚本。没设图标时仍取站点名首字，老站升级外观不变（页脚各项全空则不渲染）。<b>② 内页标题修复</b>：除首页外，内页 <code>&lt;title&gt;</code> 以前只有「账单」「分组」这类页面名、不带站点名，现在统一为「页面名 · 站点名」。<b>③ 侧栏订阅入口</b>：左侧下方新增「订阅会员」（带「升级」角标，点进账单挑套餐）；<b>已订阅则按钮自动隐藏</b>，原位改成「会员用户 + 套餐名」的会员标识，悬停看到到期日，点了进账单看详情。判断标准是「当前有生效中的月付套餐」，所以套餐到期、被退款作废、只买过积分包、或 AI 模块整体关闭，都按未订阅处理（不显示假会员）。地址与 API 全部不变。</td></tr>
            <tr><td><code>2.3.1</code></td><td>修 2.3.0 权限收紧带来的两个回归 + 七牛上传域名问题。<b>① AI 查不到知识库（重要）</b>：<code>kb_groups</code> 以前只返回「有写权限」的分组，只有读授权的账号（别人授权给你、或唯一的公共库）拿到空列表，模型据此断言「没有任何知识库」直接放弃检索——库里明明有内容。<b>现在 <code>kb_groups</code> 列出全部可检索分组</b>（<code>writable=false</code> 即只读），并带上分组条数与总条数；提示词也同步改掉「只列有写权限的分组」这句误导。搜不到时不再说「没有可读的分组」，而是说明「在 N 个分组 / M 条内容里都没命中，换个更短的关键词」。<b>② 整句提问检索落空</b>：中文按二字组覆盖率算分，把「帮我看下知识库里关于积分套餐的内容」整句丢进去会被废话词稀释成 0 命中。<b>新增检索词归一化</b>（<code>agent_search_terms()</code>）：自动剥掉提问话术与虚词、按段取中文实词串，先按原句搜、空了再用拆词结果搜一轮，AI 工作台与 AI 助手共用；提示词也明确要求 query 只给 2~6 个实词、没命中就换同义词再试。<b>③ 七牛「测试连接提示连接失败」</b>：真实返回是 <code>connect refused, please use up-z2.qiniup.com, bucket is in z2</code>——七牛已告知正确上传域名，只是空间不在默认区域。<b>现在会自动从报错里解析出正确域名并重试</b>，成功即写回配置（<code>auto_host</code>，后续真实上传不再走错）；解析不到时才报错，且文案说清「域名与空间区域不匹配 + 两种改法 + 原始返回」，不再只丢一句「连接失败」。地址与 API 全部不变。</td></tr>
            <tr><td><code>2.3.0</code></td><td>管理员身份拆成<b>「前台 / 后台」两套</b>：管理系统全站的功能与页面只从左侧「管理后台」进，回到用户页面（首页 / 分组 / 条目 / 搜索 / AI 工作台 / AI 助手 / 文件）后，管理员与普通用户<b>完全同权同价</b>。<b>① 权限</b>：前台只认「自己建的分组 + 被授权的分组」，不再因为 <code>role=admin</code> 放出全站分组与别人的知识库；后台 <code>?r=admin*</code> 才全通（<code>require_admin()</code> 打上下文标记，<code>perm_root()</code> 才成立）。<b>② 计费</b>：AI 不再给管理员免单——前台余额不足照样被门禁卡片拦下，同一套积分 / 套餐约束；要给自己加积分或开套餐去「管理后台 → AI 与套餐」。<b>③ 后台操作不再借前台路由</b>：新增 <code>admin_entry_edit</code>（内容编辑 / 新建）、<code>admin_entry_view</code>（内容查看）、<code>admin_file</code>（文件预览下载），后台的保存 / 删除 / 查看都留在后台。<b>④ 共享分组补成员关系</b>：后台新建的公共分组（<code>owner_id=0</code>）自动把创建者记为成员，迁移 <code>2.3.0</code> 给现有共享分组补上管理员授权，避免升级后公共库「谁都看不见」。<b>⑤</b> 左侧「我的分组」固定按前台口径，后台页面不会突然列出全站分组。<b>⑥</b> 提示词与界面同步：AI 不再声称管理员能看全站，工作台底部、账户页角色、后台概览都写明前台同权。地址与 API 全部不变。</td></tr>
            <tr><td><code>2.2.5</code></td><td>AI 会话新增 <b>「计费 / 模型门禁」</b>：回答前先判断所选模型属于哪种模式。<b>① 积分扣除（系统模型）</b>：先看余额，不足时不再白跑一次调用，直接在<b>会话窗口内自动显示引导卡片</b>，带「去充值 / 买套餐」「接入自定义模型」入口，已有自定义模型还能一键切换过去；<b>② 自定义接入模型</b>：先校验月付套餐与接口配置（缺地址 / 模型名 / API Key 都写明），接口连不通时把域名解析、TCP、TLS、超时、401 / 429 / 404 等<b>具体报错原因</b>翻成人话，并给「去我的模型测试连接」「切回系统模型」入口。进页面、切换模型、每轮发送前都会重新判断，卡片随状态自动出现 / 消失；拦截时<b>不预扣、不调用、不记失败日志</b>，失败调用仍全额退积分。AI 工作台与 AI 助手（对话 / 优化答案 / 文本转 QA）统一生效，引导卡片落库，刷新后仍在且不会自动续跑。另修复「会话记住的模型被删除后默认选中回退」。离线上传 zip 入口保留，地址与 API 全部不变。</td></tr>
            <tr><td><code>2.2.4</code></td><td>修复 <b>AI 工作台「对话报错 / 满屏 JSON」</b>：模型偶尔会把多个动作塞进一个 JSON 数组（<code>[{call...},{done...}]</code>），旧解析器只认单个动作对象，解析失败后把<b>整段协议原文当回答显示</b>，工具根本没执行，还采纳了「没查工具就下的结论」。现在 <code>agent_parse_action()</code> 支持动作数组与 <code>actions / steps</code> 包装，<b>优先执行第一个带 tool 的 call</b>（让系统真去查知识库），没有 call 才取 <code>done / ask</code>；解析不出动作的协议文本不再整屏喷出，会抽出 <code>say</code> 成人话，实在不行给一句可操作提示（原文仍完整存库供排查）。提示词明确禁止输出数组、禁止一次给多个动作。历史消息渲染同步受益。离线上传 zip 入口保留，地址与 API 全部不变。</td></tr>
            <tr><td><code>2.2.3</code></td><td>修复<b>七牛云上传报 <code>HTTP 401 {"error":"bad token"}</code></b>：上传凭证（uptoken）原来只生成两段 <code>AK:Sign</code>，且 <code>Sign</code> 错签在原始 JSON 策略上；七牛实际要求三段 <code>AK:Sign:EncodedPutPolicy</code>，并且 <code>Sign = base64url(HMAC-SHA1(SecretKey, EncodedPutPolicy))</code> —— 签的是 <b>编码后的策略串</b>。现已按官方 SDK 算法重写，上传、「测试连接」、probe 恢复正常。同时：401 错误会带出密钥 / 空间名 / 服务器时间 / 上传区域域名四项排查指引；公开空间下载凭证被 CDN 拒绝时自动回退裸 URL；服务器 PHP 缺 CA 根证书时给出 <code>curl.cainfo / openssl.cafile</code> 配置指引（<b>不静默关闭 TLS 校验</b>）。私有下载签名、QBox 管理 API 签名、域名容错均已回归验证。离线上传 zip 入口保留，地址与 API 全部不变。</td></tr>
            <tr><td><code>2.2.2</code></td><td>① <b>左栏合并扩展到全站</b>：首页、知识库分组页、AI 工作台的左侧栏现在都同时有「我的分组」和「AI 工作台 / 对话列表 / ＋ 新对话」，不再只在工作台页出现；主内容区仍各走各的页面（首页还是分组卡片、分组页还是条目列表、工作台还是对话窗口）。会话侧栏由 <code>nav_agent_side()</code> 统一渲染，非工作台页也能新建、重命名、删除会话。② 修复<b>文件存储配置保存后「值被改 / 参数不生效」</b>：编辑表单里 6 个驱动的字段块共用 <code>bucket/ak/sk/domain</code> 等同名 name，隐藏块照样会提交，后面的驱动块把前面填的值覆盖掉了；现改为按驱动分组命名 + 非当前驱动字段服务端 <code>disabled</code> + 关闭浏览器自动填充，并拦截被密码管理器填入的掩码串覆盖真密钥。③ 七牛「绑定域名 / 上传域名」容错去掉协议与路径，字段提示写明正确填法。手动上传 zip 的离线升级入口保留。地址与 API 全部不变。</td></tr>
            <tr><td><code>2.2.1</code></td><td>左栏首次合并：「我的分组」改为所有页面常驻，AI 工作台的「对话列表 + 新对话」由替换分组列表改为追加在其下方（该版本仅工作台页出现会话块，2.2.2 起扩展到全站）。主区未动，地址与 API 全部不变。</td></tr>
            <tr><td><code>2.2.0</code></td><td>后台「系统与升级」新增 <b>GitHub 在线更新</b>：点「检查更新 → 立即更新」，系统从公开仓库的 <code>updates.json</code> 清单按 <code>min_version</code> 自动连升多个版本，逐个下载并校验 <b>sha256</b>，升级前自动备份数据库，<code>config.php</code> / <code>storage/</code> / <code>data/</code> 不覆盖。可配置仓库、分支、<b>更新源镜像</b>（国内服务器反代）与 TLS 校验；连不上 GitHub 时会给出可操作提示，手动上传 zip 的入口仍保留。发版流程同步固化：<code>tools/publish_github.php</code> 会把更新包与清单推到 GitHub，以后每次发版仓库与后台更新源始终一致。地址与 API 全部不变。</td></tr>
            <tr><td><code>2.1.1</code></td><td>AI 工作台的会话能管起来了：<b>「＋ 新对话」会真正新建一条会话并进入</b>（此前跳回不带 id 的地址，被后端自动回落到最近一条会话，看起来点了没反应）；侧栏每条会话右侧新增 <b>「⋯」菜单</b>，可就地<b>重命名、删除</b>，对话窗口顶部也有「✎ 重命名 / 删除对话」；连点新对话不再堆积空白会话。全新安装的默认站点名改为 <b>XC知识库系统</b>（已有站点在「管理后台 → 系统设置」改站点名称即可）。地址与 API 全部不变。</td></tr>
            <tr><td><code>2.1.0</code></td><td>导航重新梳理，入口不再重复：左侧栏只留<b>分组 / AI 工作台 / 账户设置 / 管理后台</b>。账户相关的页收进「账户设置」顶部的横向子菜单（账号资料 · 账单 · 我的 API Key），<b>AI 助手并进「账单」</b>，与充值、订单同一栏，侧栏不再单列。<b>管理后台</b>同样有了横向子菜单（概览 / 用户 / 分组 / 授权 / 内容 / 导入 / API Key / 文件存储 / AI 与套餐 / 支付 / 邮件 / 系统与升级），管理员在前台侧栏不再看到「用户的分组」，别人的分组统一从后台进。AI 工作台改为<b>整屏固定的对话窗口</b>：会话列表和「新对话」移到左侧栏，页面本身不出滚动条，只有对话记录那一列滚。地址与 API 全部不变（<code>?r=recharge</code> / <code>?r=ai</code> 仍可直接访问）。</td></tr>
            <tr><td><code>2.0.4</code></td><td>修掉一个能白拿积分的漏洞：<b>月付套餐退款时只把套餐置失效、赠送的积分没扣</b>（买月付 → 申请退款 → 积分留着继续用）。现在按 <code>user_plans.credits_granted</code> 的实际发放量扣回，余额不够就扣到 0，差额写进退款备注和审计日志。后台「支付设置」新增<b>订单与积分对账</b>：每笔单算出应发 / 实发 / 已扣回 / 净发放，自动标出退款未收回、已支付未发货、重复发货、发货不足、缺平台流水号、待支付挂太久，并附<b>积分流水</b>（谁加的、谁扣的、关联哪一单）。后台各设置页做收敛：接口配置、退款自检结果、兑换码、订单、提示词、发送记录、升级记录等长内容改为折叠块，明细走右侧抽屉。API 无变更。</td></tr>
            <tr><td><code>2.0.3</code></td><td>退款排查有据可依：支付设置新增<b>「退款接口自检」</b>——先查商户信息（能读到就直接显示退款 API 是否开启，并核对平台回显的密钥与本地是否一致），再用一个<b>不存在的订单号</b>探一次退款接口，把「平台没开权限」和「单号/金额/通道问题」彻底分开，全程不产生真实退款。同时把最常踩的坑写进提示与文档：<b>「开启商户自助退款」和「订单退款 API」是两个开关</b>，前者只允许在商户后台人工点按钮，后者才允许程序调用。API 无变更。</td></tr>
            <tr><td><code>2.0.2</code></td><td>AI 助手加了明确的回答边界：只回答「本知识库怎么用」和「你能读到的知识库内容」，无关话题（写代码、算题、翻译、闲聊、时事等）直接拒答；业务问题必须先在<b>你有读权限的全部分组</b>（自己建的 + 别人授权给你的）里检索，查不到就说没有，不再拿模型记忆编。旧对话页（<code>?r=ai</code>）也接入了检索，命中条目连分组名与条目号一起给模型。退款侧：支付平台没给商户号开退款权限时，报错翻成人话并指路；新增<b>「线下已退」</b>兜底（不调平台接口，只标记已退款并回收积分/套餐）；「查单 / 同步」能识别平台后台已退款并自动同步本站状态。API 无变更。</td></tr>
            <tr><td><code>2.0.1</code></td><td>模型连不上时能看清卡在哪一步：网络错误分 <b>域名解析 / TCP 连接 / TLS 握手 / 已连上但上游不回</b> 四类提示，后台模型行新增<b>诊断</b>按钮（分段体检 + 可选深度诊断），「测试连接」最多等 45 秒。另修正升级包会把更新说明写空的问题。API 无变更。</td></tr>
            <tr><td><code>2.0.0</code></td><td>新增 <b>AI 工作台</b>：对话窗口里可以真的干活——抓取网页正文、检索知识库、新建分组、把整理好的问答/文档写进分组，最多 8 步，每步都显示成工具卡片，写错能撤销。抓网页做了 SSRF 防护（只放行公网 http/https、限跳转与体积），写入按当前用户的真实分组权限校验。另新增<b>退款申请</b>：用户对已支付订单提交原因，后台同意后自动调平台退款并回收积分（非在线支付订单只做线下确认）。API 无破坏性变更。</td></tr>
            <tr><td><code>1.9.1</code></td><td>支付改为本页弹窗：能拿到二维码内容就直接渲染二维码（手机扫码付），平台只给收银台地址时内嵌页面并保留「新窗口打开」兜底；付完弹窗自动显示成功并提示关闭。兑换区可配置「购买兑换码」入口（后台填名称与地址）。API 无变更。</td></tr>
            <tr><td><code>1.9.0</code></td><td>站内新增在线支付（彩虹易支付 V1：MD5 签名、收银台/接口支付、查单与退款），充值页可直接付款；支付模块独立于 AI 层，回调验签、金额核对、重复通知幂等、通知丢失可主动查单补发货。API 无破坏性变更。</td></tr>
            <tr><td><code>1.8.0</code></td><td>AI 能力层：OpenAI 兼容 / Anthropic / Gemini 三协议模型接入，按次扣积分；<code>credits</code> 钱包与流水、月付套餐（解锁自带模型与每日额度）、兑换码、订单（预留微信/支付宝回调）；新增 <code>/v1/ai/*</code> 六个接口与两个 MCP 工具。</td></tr>
            <tr><td><code>1.7.1</code></td><td>多平台副本同步优先「云端直搬」（同厂商同账号走服务端复制，数据不经过本站、不占磁盘），跨厂商才用本地临时目录中转；中转前校验磁盘余量并自动清理残留。</td></tr>
            <tr><td><code>1.7.0</code></td><td>文件存储：本地磁盘 + OSS / COS / 七牛 / 又拍云 / R2 多平台接入，上传多副本、后台可手动同步补齐；新增 <code>/v1/attachments</code> 四个接口；正文支持图片。</td></tr>
            <tr><td><code>1.6.2</code></td><td>安装包内新增 <code>DEPLOY.md</code> 部署手册与 <code>nginx.example.conf</code>；安装向导把安全规则做成必做步骤，装完给出复制与自检入口。</td></tr>
            <tr><td><code>1.6.1</code></td><td>安全加固：登录失败按 IP 限速、补安全响应头、屏蔽 <code>storage/</code> 与 <code>data/</code> 直链。</td></tr>
            <tr><td><code>1.6.0</code></td><td>新增本开发文档页（免登录）与给 Agent 读的 <code>/llms.txt</code>。</td></tr>
            <tr><td><code>1.5.0</code></td><td>新增 MCP 端点 <code>/mcp.php</code>（5 个工具）与 OpenAPI 描述 <code>/openapi.php</code>；后台可一键关闭 MCP。</td></tr>
            <tr><td><code>1.4.0</code></td><td>昵称全站唯一；分组名带归属前缀。</td></tr>
            <tr><td><code>1.3.0</code></td><td>邮件：注册验证码、找回密码、通知、账户自助管理。</td></tr>
            <tr><td><code>1.1.0</code></td><td>写入改为幂等 upsert（<code>qfp</code>/<code>afp</code> 指纹 + 版本记录）；Key 分组成为硬边界。</td></tr>
          </tbody>
        </table>
      </div>

      <p class="tiny muted" style="margin-top:22px">
        接口以本页为准；机器可读描述见 <a href="<?= e($oas) ?>">OpenAPI</a>，
        纯文本索引见 <a href="<?= e($llms) ?>">llms.txt</a>。
        有问题请带上请求 id、时间与响应体找管理员。
      </p>
    </section>

    <?= site_footer_html() ?>
  </main>
</div>

<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
<script>
(function () {
  var btn = document.getElementById('docsMenuBtn');
  var nav = document.getElementById('docsNav');
  if (btn && nav) {
    btn.addEventListener('click', function () { nav.classList.toggle('open'); });
    nav.addEventListener('click', function (ev) {
      if (ev.target.tagName === 'A') nav.classList.remove('open');
    });
  }
})();
</script>
</body>
</html>
