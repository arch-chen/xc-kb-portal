<?php /** @var array $ver @var string $msg @var string $err @var array $history @var string $php @var string $driver */ ?>
<section class="page-head">
  <div>
    <h1 class="page-title">系统与升级</h1>
    <p class="page-desc">当前版本 v<?= e($ver['version'] ?? APP_VERSION) ?>（<?= e($ver['released'] ?? '') ?>）</p>
  </div>
</section>

<?php if ($msg !== ''): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

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
    <p>3. 升级失败时数据库备份在 <code>storage/backups/</code>，把文件恢复回去即可。</p>
  </div>
    </div>
  </details>
</section>
