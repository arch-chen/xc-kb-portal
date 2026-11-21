<?php /** @var string $err */ ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title('登录')) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
</head>
<body class="login-body">
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <span class="brand-mark">知</span>
        <div>
          <div class="login-title"><?= e(app_name()) ?></div>
          <div class="login-sub">XC知识库系统</div>
        </div>
      </div>

      <?php if ($err !== ''): ?>
        <div class="flash err"><?= e($err) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= e(u('login')) ?>" class="login-form">
        <?= csrf_field() ?>
        <label>账号
          <input type="text" name="username" autocomplete="username" required autofocus
                 value="<?= e(post('username')) ?>">
        </label>
        <label>密码
          <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn primary block" type="submit">登录</button>
      </form>

      <div class="login-foot">
        <?php if (register_allowed()): ?>
          <a class="link" href="<?= e(u('register')) ?>">注册新账号</a>
        <?php endif; ?>
        <?php if (mail_ready()): ?>
          <a class="link" href="<?= e(u('forgot')) ?>">忘记密码</a>
        <?php endif; ?>
        <a class="link" href="<?= e(u('docs')) ?>">开发文档</a>
        <button type="button" class="link-btn" id="themeBtn">切换主题</button>
      </div>
    </div>
  </div>
<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
</body>
</html>
