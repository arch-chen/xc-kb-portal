<?php
/** @var string $content */
$u = current_user();
$navGroups = $u ? my_groups((int)$u['id']) : [];
$route = get_q('r');
$f = flash();
$section = $u ? nav_section($route) : '';
$isAdmin = $u ? is_admin($u) : false;
// 当前分组：分组页地址是 ?r=g&slug=xxx。侧栏「⋯」改名成功后要把主区标题一起改掉，
// 所以这里把当前分组的 id 交给前端（body 的 data-cur-group-id）。
$curSlug = $route === 'g' ? (string)get_q('slug', '') : '';
$curGroupId = 0;
if ($curSlug !== '') {
    foreach ($navGroups as $ng) {
        if ((string)$ng['slug'] === $curSlug) {
            $curGroupId = (int)$ng['id'];
            break;
        }
    }
}
// 左栏常驻两块：「我的分组」+「AI 工作台（对话列表 / 新对话）」，所有页面都在。
// 会话侧栏由 nav_agent_side() 统一渲染，视图不再各自塞 side_panel。
// 主区内容仍按路由各自渲染，首页 / 分组页 / AI 工作台依旧是独立页面。
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title((string)($title ?? ''))) ?></title>
<?= site_favicon_tag() ?>
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
<body<?= $curGroupId > 0 ? ' data-cur-group-id="' . $curGroupId . '"' : '' ?>>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(u()) ?>">
      <?= site_brand_mark() ?>
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
        // 侧栏只列「我的分组」。my_groups() 已经是前台口径（自己建的 + 被授权的），
        // 所以不再按 is_admin 过滤——管理员的前台身份和普通用户一样（2.3.0）。
        $sideMine = $navGroups;
        ?>
        <?php if (!$sideMine): ?>
          <div class="side-empty"><?= $isAdmin ? '你还没有自己的分组；可到「管理后台 → 分组」里建，或让别人授权给你' : '还没有被授权的分组' ?></div>
        <?php endif; ?>
        <?php foreach ($sideMine as $g): ?>
          <?php
          // 「⋯」只在有权限改名时出现：私有分组给归属人，共享分组给管理员。
          // 判定与后端 ctrl_group_rename 同一份（can_rename_group），不会出现
          // 「按钮点得到但服务端拒绝」。
          $canRename = $u ? can_rename_group((int)$u['id'], (int)$g['id']) : false;
          $gPrefix = (int)$g['owner_id'] > 0 ? (group_owner_nick($g) . GROUP_SEP) : '';
          ?>
          <div class="side-group-row" data-id="<?= (int)$g['id'] ?>"
               data-slug="<?= e((string)$g['slug']) ?>"
               data-name="<?= e((string)$g['name']) ?>"
               data-prefix="<?= e($gPrefix) ?>">
            <a class="side-item <?= (string)$g['slug'] === $curSlug ? 'active' : '' ?>"
               href="<?= e(u('g', ['slug' => $g['slug']])) ?>">
              <span class="dot"></span>
              <span class="side-item-text"><?= e($g['name']) ?></span>
            </a>
            <?php if ($canRename): ?>
              <button type="button" class="side-group-more" aria-label="分组操作"
                      title="重命名" data-id="<?= (int)$g['id'] ?>">⋯</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </nav>
    </div>

    <?= $u ? nav_agent_side((int)$u['id'], (int)($GLOBALS['agent_run_id'] ?? 0)) : '' ?>

    <div class="side-foot">
      <?= nav_subscribe_item($u) ?>
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
      <span class="topbar-spacer"></span>
      <?= github_corner_link('inline') ?>
    </div>

    <?php if ($f): ?>
      <div class="flash <?= e($f['t']) ?>"><?= e($f['m']) ?></div>
    <?php endif; ?>

    <?= nav_subnav($route) ?>

    <?= $content ?>

    <?= site_footer_html() ?>
  </main>
</div>
<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
<script src="<?= e(asset('group-rename.js')) ?>?v=<?= e(asset_ver('group-rename.js')) ?>"></script>
<script src="<?= e(asset('ai-guide.js')) ?>?v=<?= e(asset_ver('ai-guide.js')) ?>"></script>
<script src="<?= e(asset('agent-side.js')) ?>?v=<?= e(asset_ver('agent-side.js')) ?>"></script>
</body>
</html>
