<?php /** @var string $err @var string $question @var int $limit @var bool $mailVerify @var bool $mailOptional */ ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title('注册')) ?></title>
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
          <div class="login-sub">创建账号</div>
        </div>
      </div>

      <?php if ($err !== ''): ?>
        <div class="flash err"><?= e($err) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= e(u('register')) ?>" class="login-form">
        <?= csrf_field() ?>
        <label>账号
          <input type="text" name="username" autocomplete="username" required autofocus
                 maxlength="32" pattern="[A-Za-z0-9_.@\-]{3,32}"
                 value="<?= e(post('username')) ?>">
        </label>
        <label>昵称<span class="field-hint">全站唯一，留空就用账号名；会作为你知识库名称的前缀</span>
          <input type="text" name="display_name" autocomplete="nickname" maxlength="40"
                 value="<?= e(post('display_name')) ?>">
        </label>

        <?php if ($mailVerify): ?>
          <label>邮箱<span class="field-hint">收验证码用，也是以后找回密码的凭据</span>
            <input type="email" name="email" autocomplete="email" required maxlength="190"
                   value="<?= e(post('email')) ?>">
          </label>
          <label>邮箱验证码
            <div class="code-row">
              <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                     maxlength="6" required placeholder="6 位数字">
              <button type="button" class="btn sm"
                      data-send-code="<?= e(u('mail_code')) ?>" data-purpose="register">发送验证码</button>
            </div>
          </label>
        <?php elseif ($mailOptional): ?>
          <label>邮箱<span class="field-hint">选填，填了才能收通知和自助找回密码</span>
            <input type="email" name="email" autocomplete="email" maxlength="190"
                   value="<?= e(post('email')) ?>">
          </label>
        <?php endif; ?>

        <label>密码
          <input type="password" name="password" autocomplete="new-password" required minlength="8">
        </label>
        <label>再输一次
          <input type="password" name="password2" autocomplete="new-password" required minlength="8">
        </label>

        <?php if (!$mailVerify): ?>
          <label>验证码：<span class="captcha-q"><?= e($question) ?></span>
            <input type="text" name="captcha" inputmode="numeric" autocomplete="off" required maxlength="3"
                   placeholder="填计算结果">
          </label>
        <?php endif; ?>

        <button class="btn primary block" type="submit">注册并进入</button>
      </form>

      <p class="login-hint">
        注册后会自动为你创建一个私有空间，里面的内容只有你自己能看到。<br>
        密码至少 8 位、不能全是数字；同一网络每小时最多注册 <?= (int)$limit ?> 个账号。
        <?php if ($mailVerify): ?><br>本站开启了邮箱验证，收不到验证码时看一眼垃圾箱。<?php endif; ?>
      </p>

      <div class="login-foot">
        <a class="link" href="<?= e(u('login')) ?>">已有账号，去登录</a>
        <button type="button" class="link-btn" id="themeBtn">切换主题</button>
      </div>
    </div>
    <?= site_footer_html() ?>
  </div>
<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
</body>
</html>
