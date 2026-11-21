<?php
declare(strict_types=1);

/**
 * 一键安装向导。
 * 流程：环境检查 → 填数据库与管理员 → 写 config.php → 建表 → 建管理员 → 上锁。
 * 安装完成后建议把这个文件删掉；即使不删，上锁后也无法重复安装。
 */

define('INSTALL_ROOT', __DIR__);
$configFile = INSTALL_ROOT . '/config.php';
$lockFile = INSTALL_ROOT . '/storage/install.lock';

// 部署文案（nginx 规则等）与后台面板共用同一份，避免两处写法漂移
require_once INSTALL_ROOT . '/app/deploy.php';
$selfBase = deploy_self_base();

/* ---------- 已安装判断 ---------- */
$alreadyInstalled = false;
if (is_file($configFile)) {
    if (is_file($lockFile)) {
        $alreadyInstalled = true;
    } else {
        try {
            $tmpCfg = require $configFile;
            $d = $tmpCfg['db'];
            $dsn = $d['driver'] === 'sqlite'
                ? 'sqlite:' . $d['sqlite_path']
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], (int)$d['port'], $d['database'], $d['charset'] ?: 'utf8mb4');
            $pdo = new PDO($dsn, $d['username'] ?? null, $d['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $n = $pdo->query('SELECT COUNT(*) FROM ' . ($d['prefix'] ?? '') . 'users')->fetchColumn();
            $alreadyInstalled = ((int)$n) > 0;
        } catch (Throwable $e) {
            $alreadyInstalled = false;
        }
    }
}

/* ---------- 环境检查 ---------- */
$checks = [
    ['PHP ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
    ['PDO 扩展', extension_loaded('pdo'), ''],
    ['mbstring 扩展', extension_loaded('mbstring'), ''],
    ['openssl 扩展', extension_loaded('openssl'), ''],
    ['zip 扩展（在线升级用）', extension_loaded('zip'), ''],
    ['根目录可写（写 config.php）', is_writable(INSTALL_ROOT), ''],
    ['storage 可写', is_writable(INSTALL_ROOT . '/storage') || @mkdir(INSTALL_ROOT . '/storage', 0775, true), ''],
    ['SQLite 模式', extension_loaded('pdo_sqlite'), '安装时可改用 MySQL'],
];
$checksOk = true;
foreach ($checks as $c) {
    if (!$c[1] && !str_contains($c[0], 'SQLite')) {
        $checksOk = false;
    }
}

$err = '';
$done = false;
$adminUser = '';
$adminPass = '';
$driverUsed = '';

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteName = trim((string)($_POST['site_name'] ?? 'XC知识库系统'));
    $driver = ($_POST['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $prefix = trim((string)($_POST['prefix'] ?? ''));
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');

    $sqlitePath = trim((string)($_POST['sqlite_path'] ?? '')) ?: INSTALL_ROOT . '/storage/kb.sqlite';
    $mysql = [
        'host' => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
        'port' => (int)($_POST['db_port'] ?? 3306),
        'database' => trim((string)($_POST['db_name'] ?? '')),
        'username' => trim((string)($_POST['db_user'] ?? '')),
        'password' => (string)($_POST['db_pass'] ?? ''),
    ];

    if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $prefix)) {
        $err = '表前缀只能用字母数字下划线';
    } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $adminUser)) {
        $err = '管理员账号只能用字母数字和 _ . @ -，3 位以上';
    } elseif (mb_strlen($adminPass) < 8) {
        $err = '管理员密码至少 8 位';
    } elseif ($adminPass !== $adminPass2) {
        $err = '两次输入的密码不一致';
    } elseif ($driver === 'mysql' && ($mysql['database'] === '' || $mysql['username'] === '')) {
        $err = 'MySQL 需要填写数据库名和用户名';
    } elseif ($driver === 'sqlite' && !extension_loaded('pdo_sqlite')) {
        $err = '当前 PHP 没有 pdo_sqlite 扩展，请改用 MySQL 或先安装该扩展';
    } else {
        $cfg = [
            'db' => [
                'driver' => $driver,
                'sqlite_path' => $sqlitePath,
                'host' => $mysql['host'],
                'port' => $mysql['port'],
                'database' => $mysql['database'],
                'username' => $mysql['username'],
                'password' => $mysql['password'],
                'charset' => 'utf8mb4',
                'prefix' => $prefix,
            ],
            'app' => [
                'name' => $siteName !== '' ? $siteName : 'XC知识库系统',
                'url' => '',
                'timezone' => 'Asia/Shanghai',
                'debug' => false,
            ],
            'security' => [
                'pepper' => bin2hex(random_bytes(24)),
                'session_name' => 'kbsid',
            ],
        ];
        $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        if (@file_put_contents($configFile, $php) === false) {
            $err = '无法写入 config.php，请给根目录写权限';
        } else {
            try {
                require INSTALL_ROOT . '/app/bootstrap.php';
                schema_install(true);
                create_user($adminUser, $adminPass, $adminUser, 'admin');
                setting_set('site_name', $cfg['app']['name']);
                setting_set('allow_register', '1');
                setting_set('mcp_enabled', '1');
                setting_set('register_per_hour', '5');
                setting_set('mail_enabled', '0');
                setting_set('mail_method', 'smtp');
                setting_set('mail_port', '465');
                setting_set('mail_secure', 'ssl');
                setting_set('mail_verify_register', '1');
                setting_set('mail_welcome', '1');
                setting_set('mail_no_verify', '0');
                setting_set('installed_at', now_str());
                setting_set('installed_version', APP_VERSION);

                // 全新安装建的已经是最新结构，把历史迁移标记为「已执行」。
                // 不标记的话，以后在后台传更新包会把 1.1.2 那种 ALTER TABLE 重跑一遍。
                foreach (glob(INSTALL_ROOT . '/migrations/*.php') ?: [] as $mf) {
                    $mv = basename($mf, '.php');
                    if (preg_match('/^\d+\.\d+\.\d+$/', $mv) && version_compare($mv, APP_VERSION, '<=')) {
                        setting_set('mig_' . $mv, now_str());
                    }
                }

                foreach (['storage/backups', 'storage/tmp'] as $sd) {
                    if (!is_dir(INSTALL_ROOT . '/' . $sd)) {
                        @mkdir(INSTALL_ROOT . '/' . $sd, 0775, true);
                    }
                }

                // 规则文件落一份在站点根：装完可直接 include，删面板配置也不怕忘
                $confFile = INSTALL_ROOT . '/nginx.example.conf';
                if (!is_file($confFile)) {
                    @file_put_contents($confFile, nginx_snippet() . "\n");
                }
                @file_put_contents($lockFile, date('c') . " installed\n");

                $done = true;
                $driverUsed = $driver === 'sqlite' ? 'SQLite' : 'MySQL';
            } catch (Throwable $e) {
                $err = '安装失败：' . $e->getMessage();
                @unlink($configFile);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装 · XC知识库系统</title>
<link rel="stylesheet" href="assets/app.css">
<script>(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
</head>
<body class="install-body">
<div class="install-wrap">

<?php if ($alreadyInstalled): ?>
  <div class="install-card">
    <h1>已经安装过了</h1>
    <p class="muted">系统检测到 <code>config.php</code> 与数据库都已就绪。重复安装会清空数据，因此已阻止。</p>
    <p>如果要重装，请手动删除 <code>config.php</code> 和 <code>storage/install.lock</code>（并自行备份数据）。</p>
    <a class="btn primary" href="index.php">进入系统</a>
  </div>

<?php elseif ($done): ?>
  <div class="install-card">
    <h1>安装完成</h1>
    <p>数据库：<b><?= htmlspecialchars($driverUsed) ?></b> · 版本 v<?= htmlspecialchars(APP_VERSION) ?></p>
    <div class="cred-box">
      <div><span>管理员账号</span><b><?= htmlspecialchars($adminUser) ?></b></div>
      <div><span>密码</span><b>你刚才设置的密码</b></div>
    </div>
    <div class="flash err" style="text-align:left">
      <b>装完还不算上线，下面三步都要做完，其中第 2 步不做等于数据裸奔。</b>
    </div>

    <h2>1. 删掉安装向导</h2>
    <p class="muted">在服务器上删除 <code>install.php</code>。不删也不会被重复安装（有 <code>storage/install.lock</code>），
      但少一个文件少一分风险。</p>

    <h2>2. 给站点加 nginx 规则（必须）</h2>
    <div class="hint">
      <p><code>storage/kb.sqlite</code>、<code>data/*.csv</code> 就在站点根目录下。nginx 会把它们当静态文件直接发出去，
        <b>不需要任何账号或 Key，一条 URL 就能下载整库</b>——密码强度、Key、字段加密都挡不住这一步。</p>
      <p><b>宝塔：</b>网站 → 设置 → 配置文件，把下面两行加到 <code>server { }</code> 内，保存并重载。
        nginx 不读 <code>.htaccess</code>，这一步只能手动做（Apache 已由内置 <code>.htaccess</code> 兜底）。</p>
    </div>
    <pre class="rule-pre"><code><?= htmlspecialchars(nginx_rules()) ?></code></pre>
    <p>
      <button type="button" class="btn sm" data-copy="<?= htmlspecialchars(nginx_rules()) ?>">复制规则</button>
      <a class="btn sm" href="<?= htmlspecialchars($selfBase) ?>/storage/kb.sqlite" target="_blank" rel="noopener">点这里自检（应显示 404）</a>
    </p>
    <p class="muted tiny">包内另带 <code>nginx.example.conf</code>，也可以只写一行
      <code>include /站点目录/nginx.example.conf;</code>。完整步骤与排错见 <code>DEPLOY.md</code>。</p>

    <h2>3. 备份这两样东西</h2>
    <p class="muted"><code>storage/</code>（数据库与备份）和 <code>config.php</code>。
      <code>config.php</code> 里有 <code>pepper</code> 与邮件密码的加密密钥——只备份库不备份它，换机器后所有 API Key 会失效、邮件密码解不开。</p>

    <p style="margin-top:22px"><a class="btn primary" href="index.php">登录系统</a></p>
  </div>

<?php else: ?>
  <div class="install-card wide">
    <h1>安装 XC知识库系统</h1>
    <p class="muted">填好下面几项就能用。默认 SQLite，零配置；也可以填 MySQL。</p>

    <details class="install-note" open>
      <summary>部署前必读：装完要给 nginx 加两行规则，否则整库能被直接下载</summary>
      <div class="hint">
        <p>安装包会把数据库放在 <code>storage/kb.sqlite</code>。nginx 不认识"这是数据库"，
          只会照 URL 把文件吐出去，<b>不需要账号或 Key</b>。装完务必把下面两行加到站点
          <code>server { }</code> 里（宝塔：网站 → 设置 → 配置文件），保存并重载。</p>
      </div>
      <pre class="rule-pre"><code><?= htmlspecialchars(nginx_rules()) ?></code></pre>
      <p><button type="button" class="btn sm" data-copy="<?= htmlspecialchars(nginx_rules()) ?>">复制规则</button></p>
      <p class="muted tiny">装完这一步也会再提示一次；完整步骤、自检命令与报错对照表见包内 <code>DEPLOY.md</code>。</p>
    </details>

    <?php if ($err !== ''): ?>
      <div class="flash err"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="check-grid">
      <?php foreach ($checks as $c): ?>
        <div class="check-item <?= $c[1] ? 'ok' : 'bad' ?>">
          <span class="dot"></span>
          <span><?= htmlspecialchars($c[0]) ?></span>
          <?php if ($c[2] !== ''): ?><span class="muted tiny"><?= htmlspecialchars($c[2]) ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!$checksOk): ?>
      <div class="flash err">有必须项未通过，请先解决再安装。</div>
    <?php endif; ?>

    <form method="post" class="form install-form">
      <label>站点名称
        <input type="text" name="site_name" value="<?= htmlspecialchars((string)($_POST['site_name'] ?? 'XC知识库系统')) ?>">
      </label>

      <div class="field-group">
        <div class="field-label">数据库</div>
        <div class="radios">
          <label class="check"><input type="radio" name="driver" value="sqlite"
            <?= (($_POST['driver'] ?? 'sqlite') === 'sqlite') ? 'checked' : '' ?> id="dSqlite"> SQLite（推荐，免配置）</label>
          <label class="check"><input type="radio" name="driver" value="mysql"
            <?= (($_POST['driver'] ?? '') === 'mysql') ? 'checked' : '' ?> id="dMysql"> MySQL</label>
        </div>
      </div>

      <div id="boxSqlite">
        <label>数据库文件路径
          <input type="text" name="sqlite_path" value="<?= htmlspecialchars((string)($_POST['sqlite_path'] ?? INSTALL_ROOT . '/storage/kb.sqlite')) ?>">
        </label>
        <div class="hint">建议放在 storage/ 下，这个目录默认禁止外部访问。</div>
      </div>

      <div id="boxMysql" style="display:none">
        <div class="form row-form">
          <label>主机<input type="text" name="db_host" value="<?= htmlspecialchars((string)($_POST['db_host'] ?? '127.0.0.1')) ?>"></label>
          <label class="narrow">端口<input type="number" name="db_port" value="<?= (int)($_POST['db_port'] ?? 3306) ?>"></label>
        </div>
        <label>数据库名<input type="text" name="db_name" value="<?= htmlspecialchars((string)($_POST['db_name'] ?? '')) ?>"></label>
        <div class="form row-form">
          <label>用户名<input type="text" name="db_user" value="<?= htmlspecialchars((string)($_POST['db_user'] ?? '')) ?>"></label>
          <label>密码<input type="text" name="db_pass" value="<?= htmlspecialchars((string)($_POST['db_pass'] ?? '')) ?>"></label>
        </div>
      </div>

      <label>表前缀（可选，多个站点共用一个库时用）
        <input type="text" name="prefix" value="<?= htmlspecialchars((string)($_POST['prefix'] ?? '')) ?>" placeholder="例如 kb_">
      </label>

      <hr class="sep">

      <div class="field-label">管理员账号</div>
      <div class="form row-form">
        <label>账号<input type="text" name="admin_user" required value="<?= htmlspecialchars($adminUser) ?>"></label>
        <label>密码（≥8 位）<input type="password" name="admin_pass" required></label>
        <label>再输一次<input type="password" name="admin_pass2" required></label>
      </div>

      <button class="btn primary block" type="submit">开始安装</button>
    </form>
  </div>
<?php endif; ?>

</div>
<script src="assets/app.js"></script>
<script>
(function () {
  var s = document.getElementById('dSqlite'), m = document.getElementById('dMysql');
  var bs = document.getElementById('boxSqlite'), bm = document.getElementById('boxMysql');
  function sync() {
    var isM = m && m.checked;
    if (bs) bs.style.display = isM ? 'none' : '';
    if (bm) bm.style.display = isM ? '' : 'none';
  }
  if (s) s.addEventListener('change', sync);
  if (m) m.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
