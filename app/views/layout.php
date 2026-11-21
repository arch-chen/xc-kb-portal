<?php
/** @var string $content */
$u = current_user();
$navGroups = $u ? visible_groups((int)$u['id']) : [];
$activeGroup = get_q('g', '');
$route = get_q('r');
$f = flash();
$section = $u ? nav_section($route) : '';
$isAdmin = $u ? is_admin($u) : false;
// 左栏常驻「我的分组」；AI 工作台会额外把「对话列表 + 新对话」塞进来（见 views/agent.php），
// 两块并列在同一个侧栏里，不再互相替换。主区内容仍按路由各自渲染，
// 首页与 AI 工作台依旧是两个独立页面。
$sidePanel = (string)($GLOBALS['side_panel'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? app_name()) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function () {
  try {
    var t = localStorage.getItem('kb-theme') || 'system';
    document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
</head>
<body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(u()) ?>">
      <span class="brand-mark">知</span>
      <span class="brand-text"><?= e(app_name()) ?></span>
    </a>

    <form class="side-search" action="<?= e(u('search')) ?>" method="get">
      <input type="hidden" name="r" value="search">
      <input type="search" name="q" placeholder="搜索…" value="<?= e(get_q('q')) ?>">
    </form>

    <div class="side-section">
      <div class="side-title">我的分组</div>
      <nav class="side-nav">
        <?php
        // 管理员能看到全站分组，但左侧只列「自己的」（含共享给别人的）。
        // 用户的分组属于后台管理范围，入口统一收在「管理后台 → 分组」里。
        $sideMine = [];
        foreach ($navGroups as $g) {
            $o = (int)$g['owner_id'];
            if ($isAdmin && $o > 0 && $o !== (int)$u['id']) {
                continue;
            }
            $sideMine[] = $g;
        }
        ?>
        <?php if (!$sideMine): ?>
          <div class="side-empty"><?= $isAdmin ? '你还没有自己的分组；用户的分组在「管理后台 → 分组」里' : '还没有被授权的分组' ?></div>
        <?php endif; ?>
        <?php foreach ($sideMine as $g): ?>
          <a class="side-item <?= (string)$g['slug'] === $activeGroup ? 'active' : '' ?>"
             href="<?= e(u('g', ['slug' => $g['slug']])) ?>">
            <span class="dot"></span>
            <span class="side-item-text"><?= e($g['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <?php if ($sidePanel !== ''): ?>
      <?= $sidePanel ?>
    <?php endif; ?>

    <div class="side-foot">
      <a class="side-item <?= $route === 'group_new' ? 'active' : '' ?>" href="<?= e(u('group_new')) ?>"><span class="ico">＋</span>新建分组</a>
      <?php if (ai_on()): ?>
        <a class="side-item <?= $route === 'agent' ? 'active' : '' ?>" href="<?= e(u('agent')) ?>"><span class="ico">⌘</span>AI 工作台</a>
      <?php endif; ?>
      <a class="side-item <?= $section === 'account' ? 'active' : '' ?>" href="<?= e(u('account')) ?>"><span class="ico">👤</span>账户设置</a>
      <?php if ($isAdmin): ?>
        <a class="side-item <?= $section === 'admin' ? 'active' : '' ?>" href="<?= e(u('admin')) ?>"><span class="ico">⚙</span>管理后台</a>
      <?php endif; ?>
      <button type="button" class="side-item theme-btn" id="themeBtn">
        <span class="ico" id="themeIco">◐</span><span id="themeLabel">跟随系统</span>
      </button>
      <form method="post" action="<?= e(u('logout')) ?>" class="logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="side-item"><span class="ico">↪</span>退出登录</button>
      </form>
    </div>
  </aside>

  <main class="main<?= $route === 'agent' ? ' main-agent' : '' ?>">
    <div class="topbar">
      <button class="menu-btn" type="button" id="menuBtn" aria-label="菜单">☰</button>
      <a class="who" href="<?= e(u('account')) ?>" title="账户设置"><?= e($u ? ($u['display_name'] ?: $u['username']) : '未登录') ?><?= $isAdmin ? ' · 管理员' : '' ?></a>
    </div>

    <?php if ($f): ?>
      <div class="flash <?= e($f['t']) ?>"><?= e($f['m']) ?></div>
    <?php endif; ?>

    <?= nav_subnav($route) ?>

    <?= $content ?>
  </main>
</div>
<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
</body>
</html>
