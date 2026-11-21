<?php /** @var bool $ready @var int $step @var string $email @var string $err */
$f = flash();
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title('找回密码')) ?></title>
<?= site_favicon_tag() ?>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
</head>
<body class="login-body">
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-brand">
        <?= site_brand_mark() ?>
        <div>
          <div class="login-title"><?= e(app_name()) ?></div>
          <div class="login-sub">找回密码</div>
        </div>
      </div>

      <?php if ($err !== ''): ?>
        <div class="flash err"><?= e($err) ?></div>
      <?php elseif ($f): ?>
        <div class="flash <?= e($f['t']) ?>"><?= e($f['m']) ?></div>
      <?php endif; ?>

      <?php if (!$ready): ?>
        <p class="login-hint" style="text-align:left">
          本站还没有启用邮件服务，没法自助找回密码。<br>
          请联系管理员在后台帮你重置（后台 → 用户 → 重置密码）。
        </p>
        <div class="login-foot">
          <a class="link" href="<?= e(u('login')) ?>">返回登录</a>
        </div>

      <?php elseif ($step === 1): ?>
        <form method="post" action="<?= e(u('forgot')) ?>" class="login-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send">
          <label>注册时用的邮箱
            <input type="email" name="email" autocomplete="email" required maxlength="190"
                   value="<?= e($email) ?>" autofocus>
          </label>
          <button class="btn primary block" type="submit">发送验证码</button>
        </form>
        <p class="login-hint">
          验证码 15 分钟内有效。如果这个邮箱没有注册过，我们不会发出任何邮件，
          因此也无法通过这里判断某个邮箱有没有账号。
        </p>
        <div class="login-foot">
          <a class="link" href="<?= e(u('login')) ?>">返回登录</a>
          <button type="button" class="link-btn" id="themeBtn">切换主题</button>
        </div>

      <?php else: ?>
        <form method="post" action="<?= e(u('forgot')) ?>" class="login-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset">
          <label>验证码
            <div class="code-row">
              <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                     maxlength="6" required placeholder="6 位数字" autofocus>
              <button type="button" class="btn sm"
                      data-send-code="<?= e(u('mail_code')) ?>" data-purpose="reset"
                      data-email="<?= e($email) ?>">重新发送</button>
            </div>
          </label>
          <label>新密码
            <input type="password" name="password" autocomplete="new-password" required minlength="8">
          </label>
          <label>再输一次
            <input type="password" name="password2" autocomplete="new-password" required minlength="8">
          </label>
          <button class="btn primary block" type="submit">重置密码</button>
        </form>
        <p class="login-hint">
          验证码发到 <b><?= e($email) ?></b>。收不到就点「重新发送」，或看垃圾箱。<br>
          密码至少 8 位、不能全是数字。
        </p>
        <div class="login-foot">
          <form method="post" action="<?= e(u('forgot')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="back">
            <button type="submit" class="link-btn">换个邮箱</button>
          </form>
          <a class="link" href="<?= e(u('login')) ?>">返回登录</a>
        </div>
      <?php endif; ?>
    </div>
    <?= site_footer_html() ?>
  </div>
<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
</body>
</html>
