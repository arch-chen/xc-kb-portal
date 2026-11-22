<?php
/**
 * 对外落地页（2.5.0）——未登录访客看到的第一个页面。
 *
 * 之前 r='' 直接就是登录后工作台，没登录就被踢到登录页，
 * 外人（搜索引擎、GitHub 来的、朋友转发的链接）根本不知道这套东西是干什么的。
 * 这里补一个正式首页：讲清核心能力，把「去 GitHub 看源码」放在显眼位置，
 * 因为是 MIT 开源项目，出处和代码本身就是最有力的说明。
 *
 * 三条硬约束：
 *   1. 必须免登录。所以这个文件里不调 require_login()，也不碰任何用户数据。
 *   2. 必须带仓库入口，且页脚那份不可隐藏（site_footer_html 里固定追加）。
 *   3. 站点名 / 副标题 / logo / 简介读 site_info()，别人装了改个名就是自己的站。
 *
 * @var string $repo  GitHub 仓库地址
 * @var bool   $aiOn  本站是否启用了 AI
 * @var bool   $reg   是否开放注册
 * @var int    $qa    全站问答数（未开放统计时为 0）
 * @var int    $doc   全站文档数
 * @var array  $caps  能力清单
 */
$sn = (string)site_info('name');
$sub = (string)site_info('subtitle');
$desc = (string)site_info('desc');
if ($sub === '') {
    $sub = '给 Agent 用的知识库中台';
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title($sub)) ?></title>
<meta name="description" content="<?= e($desc !== '' ? $desc : $sn . ' —— ' . $sub . '。自带 REST / MCP / OpenAPI 接口，Agent 可直接读写；MIT 开源，可自行部署。') ?>">
<?= site_favicon_tag() ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($sn) ?> · <?= e($sub) ?>">
<meta property="og:description" content="<?= e($desc !== '' ? $desc : $sub) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
</head>
<body class="land-body">

<header class="land-top">
  <div class="land-top-in">
    <a class="land-brand" href="<?= e(u('landing')) ?>">
      <?= site_brand_mark('brand-mark') ?>
      <span class="land-brand-text"><?= e($sn) ?></span>
    </a>
    <nav class="land-top-nav">
      <a href="#features">功能</a>
      <a href="#start">怎么用</a>
      <a href="#api">接口</a>
      <a href="<?= e(llms_url()) ?>">llms.txt</a>
    </nav>
    <div class="land-top-act">
      <?= github_corner_link('inline') ?>
      <?php if (current_user()): ?>
        <a class="btn primary" href="<?= e(u()) ?>">进入工作台</a>
      <?php else: ?>
        <a class="btn" href="<?= e(u('login')) ?>">登录</a>
        <?php if ($reg): ?>
          <a class="btn primary" href="<?= e(u('register')) ?>">免费注册</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</header>

<section class="land-hero">
  <div class="land-hero-in">
    <div class="land-pill">
      <?= github_mark_svg(13) ?>
      <span>MIT 开源 · 单文件部署 · 零外部依赖</span>
    </div>
    <h1 class="land-h1"><?= e($sn) ?><br><span class="land-h1-sub"><?= e($sub) ?></span></h1>
    <p class="land-lead">
      <?= e($desc !== '' ? $desc : '把散在群聊、文档、笔记里的经验问答，收成一套有分组、有权限、能被 Agent 直接调用的知识库。') ?>
    </p>
    <div class="land-cta">
      <?php if (current_user()): ?>
        <a class="btn primary lg" href="<?= e(u()) ?>">进入工作台</a>
      <?php else: ?>
        <a class="btn primary lg" href="<?= e(u('login')) ?>">登录使用</a>
        <?php if ($reg): ?>
          <a class="btn lg" href="<?= e(u('register')) ?>">注册新账号</a>
        <?php endif; ?>
      <?php endif; ?>
      <a class="btn lg gh-cta" href="<?= e($repo) ?>" target="_blank" rel="noopener noreferrer">
        <?= github_mark_svg(16) ?><span>查看源码</span>
      </a>
    </div>
    <?php if ($qa > 0 || $doc > 0): ?>
      <div class="land-stats">
        <div><b><?= number_format($qa) ?></b><span>条问答</span></div>
        <div><b><?= number_format($doc) ?></b><span>篇文档</span></div>
        <div><b>3</b><span>种接入方式</span></div>
        <div><b>0</b><span>外部依赖</span></div>
      </div>
    <?php endif; ?>  </div>
</section>

<section class="land-sec" id="features">
  <div class="land-sec-in">
    <div class="land-sec-head">
      <h2>它是怎么工作的</h2>
      <p>一套 PHP + SQLite 的单体应用，前后台、接口、AI 全在一个目录里，扔上虚拟主机就能跑。</p>
    </div>
    <div class="land-grid">
      <?php foreach ($caps as $c): ?>
        <article class="land-card">
          <div class="land-card-ico"><?= $c['ico'] ?></div>
          <h3><?= e($c['title']) ?></h3>
          <p><?= e($c['text']) ?></p>
          <?php if (!empty($c['tags'])): ?>
            <div class="land-tags">
              <?php foreach ($c['tags'] as $t): ?><span><?= e($t) ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="land-sec land-sec-alt" id="start">
  <div class="land-sec-in">
    <div class="land-sec-head">
      <h2>三步用起来</h2>
      <p>不需要 Docker，不需要 composer，不需要 Node。有 PHP 8.1 和 SQLite 就够。</p>
    </div>
    <ol class="land-steps">
      <li>
        <div class="land-step-n">1</div>
        <div>
          <h3>传上去，打开 install.php</h3>
          <p>解压到站点目录，浏览器访问 <code>install.php</code>，填站点名和管理员账号，自动建表并写好 config.php。</p>
        </div>
      </li>
      <li>
        <div class="land-step-n">2</div>
        <div>
          <h3>建分组，把知识灌进去</h3>
          <p>后台建分组、分配权限；内容可以手写，也可以让本地 Agent 用接口批量推——按问题指纹去重，改过的答案留版本。</p>
        </div>
      </li>
      <li>
        <div class="land-step-n">3</div>
        <div>
          <h3>把接口交给你的 Agent</h3>
          <p>建一个绑定分组的 API Key，Agent 就能按权限检索、写入。分组边界是硬约束，管理员的 Key 也跨不了组。</p>
        </div>
      </li>
    </ol>
  </div>
</section>

<section class="land-sec" id="api">
  <div class="land-sec-in">
    <div class="land-sec-head">
      <h2>三种接入方式，同一套权限</h2>
      <p>不管走哪条路，能读什么、能写什么，都由 Key 绑定的分组决定。</p>
    </div>
    <div class="land-api">
      <div class="land-api-col">
        <div class="land-api-h"><b>REST</b><code>/api.php?path=/v1/search</code></div>
        <p>最通用。搜索、读取、写入、增量拉取、全量导出都有，返回 JSON。</p>
      </div>
      <div class="land-api-col">
        <div class="land-api-h"><b>MCP</b><code>/mcp.php</code></div>
        <p>无状态 Streamable HTTP，Claude、ChatGPT 连接器这类客户端填个地址就能挂上。</p>
      </div>
      <div class="land-api-col">
        <div class="land-api-h"><b>OpenAPI</b><code>/openapi.php</code></div>
        <p>动态生成的 3.0.3 描述，直接丢给会看文档的模型或 Postman。</p>
      </div>
    </div>
    <div class="land-api-foot">
      <a class="btn" href="<?= e(u('docs')) ?>">看开发文档</a>
      <a class="btn" href="<?= e(llms_url()) ?>">llms.txt</a>
      <?php if ($aiOn): ?>
        <span class="land-note">本站已启用 AI 助手，登录后可在工作台里直接对话、让它帮你查和写。</span>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="land-sec land-sec-repo">
  <div class="land-sec-in land-repo">
    <div class="land-repo-mark"><?= github_mark_svg(38) ?></div>
    <div class="land-repo-text">
      <h2>代码都在 GitHub 上</h2>
      <p>MIT 协议，单版本全功能，没有「专业版开关」。提 issue、fork 改造、提 PR 都欢迎。装在你自己服务器上的站点，每一页都会留一个回仓库的入口——那是它该有的出处。</p>
    </div>
    <a class="btn primary lg" href="<?= e($repo) ?>" target="_blank" rel="noopener noreferrer">打开仓库</a>
  </div>
</section>

<?= site_footer_html() ?>

<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
</body>
</html>
