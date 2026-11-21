<?php /** @var array $ver @var string $msg @var string $err @var array $history @var string $php @var string $driver */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">系统与升级</h1>
    <p class="page-desc">当前版本 v<?= e($ver['version'] ?? APP_VERSION) ?>（<?= e($ver['released'] ?? '') ?>）</p>
  </div>
</section>

<?php if ($msg !== ''): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

<?php
$ghChain = is_array($ghCheck ?? null) ? (array)($ghCheck['chain'] ?? []) : [];
$ghTarget = $ghChain ? (string)$ghChain[count($ghChain) - 1]['version'] : (string)($ghCheck['latest'] ?? '');
?>
<section class="panel">
  <h2 class="panel-title">GitHub 在线更新 <span class="tag">推荐</span></h2>
  <div class="hint">
    默认经 <b>GitHub API</b>（<code>api.github.com</code>，国内可达）从公开仓库
    <code><?= e((string)$ghRepo) ?></code> 的 <code><?= e((string)$ghBranch) ?></code> 分支读取
    <code>updates.json</code> 清单，按 <code>min_version</code> 自动连升多个版本；每个包都校验 sha256，
    升级前自动备份数据库，<code>config.php</code> / <code>storage/</code> / <code>data/</code> 不会被覆盖。
    清单地址：<span class="gh-src mono tiny"><?= e((string)$ghManifestUrl) ?></span>
  </div>

  <div class="gh-actions">
    <form method="post" action="<?= e(u('admin_system')) ?>" data-gh-busy="正在检查…">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="gh_check">
      <button class="btn sm" type="submit">检查更新</button>
    </form>
    <form method="post" action="<?= e(u('admin_system')) ?>" data-gh-busy="正在更新，请勿关闭页面…"
          onsubmit="return confirm('将从 GitHub 下载并自动升级，期间请勿关闭页面。确定继续？')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="gh_update">
      <button class="btn primary sm" type="submit">立即更新</button>
    </form>
  </div>

  <?php if (is_array($ghResult ?? null)): ?>
    <div class="flash <?= !empty($ghResult['ok']) ? 'ok' : 'err' ?>">
      <?= e((string)($ghResult['message'] ?? '')) ?>
      <?php if (!empty($ghResult['steps'])): ?>
        <ul class="gh-steps">
          <?php foreach ($ghResult['steps'] as $s): ?>
            <li>v<?= e((string)($s['version'] ?? '')) ?>：<?= e((string)($s['message'] ?? '')) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (is_array($ghCheck ?? null)): ?>
    <?php if (empty($ghCheck['ok'])): ?>
      <div class="flash err">检查更新失败：<?= e((string)($ghCheck['error'] ?? '')) ?></div>
    <?php elseif (!empty($ghCheck['up_to_date'])): ?>
      <div class="flash ok">已是最新版本 v<?= e((string)($ghCheck['current'] ?? '')) ?>
        （检查于 <?= e((string)($ghCheck['checked_at'] ?? '')) ?>）</div>
    <?php elseif (!empty($ghCheck['broken'])): ?>
      <div class="flash err">仓库最新是 v<?= e((string)($ghCheck['latest'] ?? '')) ?>，但当前 v<?= e((string)($ghCheck['current'] ?? '')) ?>
        与发布链之间存在缺口（min_version 对不上）。请手动上传中间版本的更新包。</div>
    <?php else: ?>
      <div class="flash ok">
        发现新版本：v<?= e((string)($ghCheck['current'] ?? '')) ?> → <b>v<?= e($ghTarget) ?></b>，
        共 <?= count($ghChain) ?> 步。点「立即更新」会自动按链连升。
        <ol class="gh-steps">
          <?php foreach ($ghChain as $s): ?>
            <li>v<?= e((string)($s['version'] ?? '')) ?><?= !empty($s['notes'])
              ? ' — ' . e(mb_substr((string)$s['notes'], 0, 80, 'UTF-8')) : '' ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <details class="fold">
    <summary>更新源设置 <span class="fold-note">仓库 / 分支 / 镜像 / 证书</span></summary>
    <div class="fold-body">
      <form method="post" action="<?= e(u('admin_system')) ?>" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="gh_settings">
        <label>更新源通道
          <select name="gh_source">
            <option value="api" <?= $ghSource === 'api' ? 'selected' : '' ?>>GitHub API（推荐 · 国内可达）</option>
            <option value="raw" <?= $ghSource === 'raw' ? 'selected' : '' ?>>raw.githubusercontent.com（国外直连）</option>
            <option value="custom" <?= $ghSource === 'custom' ? 'selected' : '' ?>>自定义镜像</option>
          </select>
        </label>
        <div class="hint">
          GitHub API 未认证限额 <b>60 次/小时</b>（检查 1 次 + 每个更新包 1 次），正常使用足够；
          国外服务器可切 raw 通道避免限流。<code>raw.githubusercontent.com</code> 在国内常被墙，别选错。
        </div>
        <label>仓库（owner/repo）
          <input type="text" name="gh_repo" value="<?= e((string)$ghRepo) ?>" maxlength="120" class="mono">
        </label>
        <label>分支
          <input type="text" name="gh_branch" value="<?= e((string)$ghBranch) ?>" maxlength="60" class="mono">
        </label>
        <label>更新源镜像（可选，留空直连 GitHub）
          <input type="text" name="gh_mirror" value="<?= e((string)$ghMirror) ?>" maxlength="240"
                 placeholder="https://mirror.example.com/https://raw.githubusercontent.com" class="mono">
        </label>
        <div class="hint">
          选「自定义镜像」时必填，按 <code>{镜像}/{owner}/{repo}/{分支}/updates.json</code> 拼接（例如
          <code>https://mirror.example.com/https://raw.githubusercontent.com</code>）。镜像必须 https，
          系统会校验解析到公网地址，拒绝内网 / 回环 / 云元数据地址。
        </div>
        <label class="check-line">
          <input type="checkbox" name="gh_ssl_verify" value="1" <?= $ghSslVerify ? 'checked' : '' ?>>
          校验 TLS 证书
        </label>
        <div class="hint">
          仅当服务器缺少 CA 根证书、报 TLS 错误时才关闭；关闭后更新包仍有 sha256 完整性校验兜底。
        </div>
        <div class="form-actions">
          <button class="btn sm" type="submit">保存更新源设置</button>
        </div>
      </form>
    </div>
  </details>
</section>

<script>
(function () {
  document.querySelectorAll('form[data-gh-busy]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type="submit"]');
      if (b) { b.disabled = true; b.textContent = f.getAttribute('data-gh-busy'); }
    });
  });
})();
</script>

<div class="two-col">
  <section class="panel">
    <h2 class="panel-title">上传更新包</h2>
    <form method="post" action="<?= e(u('admin_system')) ?>" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upgrade">
      <label>更新包（.zip）
        <input type="file" name="package" accept=".zip" required>
      </label>
      <div class="hint">
        包内结构：<code>version.json</code> + <code>files/</code> + 可选 <code>migrations/*.php</code>。
        升级前会自动备份数据库（SQLite），<code>config.php</code> 和 <code>storage/</code> 不会被覆盖。
      </div>
      <button class="btn primary" type="submit"
              onclick="return confirm('确定用这个包升级？建议先备份站点文件。')">开始升级</button>
    </form>
  </section>

  <section class="panel">
    <h2 class="panel-title">运行环境</h2>
    <div class="kv">
      <div><span>程序版本</span><b>v<?= e(APP_VERSION) ?></b></div>
      <div><span>PHP</span><b><?= e($php) ?></b></div>
      <div><span>数据库</span><b><?= e($driver === 'sqlite' ? 'SQLite' : 'MySQL') ?></b></div>
      <div><span>时区</span><b><?= e(date_default_timezone_get()) ?></b></div>
      <div><span>zip 扩展</span><b><?= class_exists('ZipArchive') ? '可用' : '缺失（无法在线升级）' ?></b></div>
      <div><span>curl 扩展</span><b><?= function_exists('curl_init') ? '可用' : '缺失（无法从 GitHub 更新）' ?></b></div>
      <div><span>上传上限</span><b><?= e(ini_get('upload_max_filesize')) ?></b></div>
      <div><span>数据目录</span><b class="mono tiny"><?= e(db_driver() === 'sqlite' ? 'storage/kb.sqlite' : (cfg()['db']['database'] ?? '')) ?></b></div>
    </div>
  </section>
</div>

<?php
$nginxRules = nginx_rules();
$nginxFull = nginx_snippet();
?>
<section class="panel">
  <h2 class="panel-title">部署安全 <span class="tag">上线必查</span></h2>
  <div class="hint">
    <p><b>数据库和种子数据就放在站点根目录下：</b><code>storage/kb.sqlite</code>、<code>storage/backups/*.sqlite</code>、
    <code>data/*.csv</code>。Web 服务器默认把目录里的文件当静态资源直接发出去——
    加密挡不住这一步，必须在服务器层禁止访问，否则任何人一条 URL 就能下走整库。</p>
    <p><b>自检：</b>浏览器直接打开 <code><?= e(base_url()) ?>/storage/kb.sqlite</code>，
    必须是 404 或 403。能下载出文件就是没配。</p>
    <p><b>宝塔：</b>网站 → 设置 → 配置文件，把下面两行加到 <code>server { }</code> 里，保存并重载。
    nginx 不读 <code>.htaccess</code>，这一步只能手动做；Apache 从 1.6.1 起已由内置 <code>.htaccess</code> 兜底。</p>
  </div>
  <pre class="rule-pre"><code><?= e($nginxRules) ?></code></pre>
  <p>
    <button type="button" class="btn sm" data-copy="<?= e($nginxRules) ?>">复制两行规则</button>
    <button type="button" class="btn sm" data-copy="<?= e($nginxFull) ?>">复制完整片段</button>
    <a class="btn sm" href="<?= e(base_url()) ?>/storage/kb.sqlite" target="_blank" rel="noopener">点这里自检（应 404）</a>
  </p>
  <div class="hint">
    <p>包内另带 <code>nginx.example.conf</code>（含可选短地址规则），可以只写一行
      <code>include /站点目录/nginx.example.conf;</code> 代替粘贴。
      安装向导在装完时也会把这两行再提示一次；完整部署步骤与报错对照表见包内 <code>DEPLOY.md</code>。</p>
    <p>万一这个地址已经被外部访问过，按「已泄露」处理：改管理员密码、吊销全部 API Key 重建。
      库里的账号密码是 bcrypt、Key 明文是 AES-GCM 加密（密钥在 <code>config.php</code>），
      但问答正文与邮箱是明文，泄露即等于内容泄露。</p>
  </div>
</section>

<section class="panel">
  <h2 class="panel-title">注册与访问</h2>
  <form method="post" action="<?= e(u('admin_system')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="settings">
    <label class="check-line">
      <input type="checkbox" name="allow_register" value="1" <?= $allowRegister ? 'checked' : '' ?>>
      允许访客自己注册
    </label>
    <div class="hint">
      开启后登录页会出现「注册新账号」。注册成功立即得到一个私有空间，内容只有本人可见（你可以审计）。
      关闭后入口隐藏，直接访问注册地址会返回「未开放」。
    </div>
    <label>同一 IP 每小时最多注册
      <input type="number" name="register_per_hour" min="1" max="100" value="<?= (int)$perHour ?>" class="tiny-input">
    </label>
    <label>站点名称
      <input type="text" name="site_name" value="<?= e(app_name()) ?>" maxlength="60">
    </label>
    <div class="hint">
      当前共 <b><?= (int)$userCount ?></b> 个账号、<b><?= (int)$spaceCount ?></b> 个私有空间。
      全站每小时上限为 <?= register_hourly_cap() ?> 个新账号（按上限的 5 倍自动算，防止被批量注册）。
    </div>
    <div class="settings-sep"></div>
    <label class="check-line">
      <input type="checkbox" name="mcp_enabled" value="1" <?= $mcpEnabled ? 'checked' : '' ?>>
      开放 MCP 接入（ChatGPT / Claude 连接器、自定义 GPT）
    </label>
    <div class="hint">
      关掉后 <code>/mcp.php</code> 直接返回 403，已有连接立刻失效；Key 和内容都不受影响，随时可以再打开。
      地址在「我的 API Key」页面查看。
    </div>
    <div class="form-actions">
      <button class="btn primary" type="submit">保存设置</button>
    </div>
  </form>
</section>

<section class="panel">
  <details class="fold">
    <summary>升级记录 <span class="fold-note">最近 <?= count($history ?? []) ?> 条</span></summary>
    <div class="fold-body">
  <?php if (!$history): ?>
    <div class="empty">还没有升级记录。</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>时间</th><th>动作</th><th>详情</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td class="tiny mono"><?= e($h['created_at']) ?></td>
            <td class="tiny"><?= e($h['action']) ?></td>
            <td class="tiny"><?= e($h['detail']) ?></td>
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
    <summary>升级操作说明 <span class="fold-note">更新包结构 · 失败怎么回滚</span></summary>
    <div class="fold-body">
  <div class="prose small">
    <p>1. 把新版本的源码目录打成 zip，结构如下：</p>
    <pre><code>update-1.1.0.zip
├── version.json        {"version":"1.1.0","min_version":"1.0.0","notes":"..."}
├── files/              app/  assets/  index.php  api.php ...
└── migrations/         1.1.0.php（可选，每个文件 return function(PDO $pdo){}）</code></pre>
    <p>2. 后台 → 系统与升级 → 选择 zip → 开始升级。系统会：校验版本 → 备份数据库 → 覆盖文件 → 执行迁移 → 写回版本号。</p>
    <p>2b. 不想手动传包就用顶部「GitHub 在线更新」：检查更新 → 立即更新，系统按 <code>min_version</code>
       自动连升多个版本，每个包都校验 sha256。更新源（仓库 / 分支 / 镜像 / 证书）可在「更新源设置」里改。</p>
    <p>3. 升级失败时数据库备份在 <code>storage/backups/</code>，把文件恢复回去即可。</p>
  </div>
    </div>
  </details>
</section>
