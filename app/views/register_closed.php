<?php /** 注册关闭时的提示页 */ ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title('注册已关闭')) ?></title>
<?= site_favicon_tag() ?>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
</head>
<body class="login-body">
<?= github_corner_link('fixed') ?>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <?= site_brand_mark() ?>
        <div>
          <div class="login-title"><?= e(app_name()) ?></div>
          <div class="login-sub">注册未开放</div>
        </div>
      </div>
      <p class="login-hint">本站目前没有开放自主注册。需要账号请联系管理员开通。</p>
      <div class="login-foot">
        <a class="link" href="<?= e(u('login')) ?>">返回登录</a>
      </div>
    </div>
    <?= site_footer_html() ?>
  </div>
<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
</body>
</html>
