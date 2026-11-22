<?php
/**
 * 登录 / 注册合一页（2.6.0）
 *
 * 需求原话：「把登录窗口和注册窗口全部优化成 16 比 9 的比例，注册和登录切换的时候
 * 不要换页面，直接再窗口里面点击切换即可。」
 *
 * 所以这里是一张 16:9 的卡片，顶部两个 tab 在同一张卡片内切换，纯 JS 不换页。
 * 两个表单都完整渲染在 DOM 里（不是异步加载），好处：
 *   - 切换零延迟，也不会因为网络抖动出现「点了没反应」
 *   - 浏览器的密码管理器能同时看见两个表单，切换后自动填充照常工作
 *   - 后退键不会在两个 tab 之间来回跳
 * 代价是首屏体积略大，但这张页面只有几十 KB，可以接受。
 *
 * 服务端仍然分开校验：登录 POST 到 r=login，注册 POST 到 r=register。
 * 两个 tab 各自是独立的 <form>，切换不会混淆字段。
 *
 * @var string $mode       'login' | 'register' —— 打开时默认显示哪个 tab
 * @var string $loginErr
 * @var string $regErr
 * @var array  $reg        注册表单需要的上下文（question/limit/mailVerify/mailOptional）
 * @var bool   $regOpen    是否允许自主注册
 * @var bool   $mailReady  是否配了邮件（决定显示「忘记密码」）
 */
$mode = ($mode ?? 'login') === 'register' ? 'register' : 'login';
if (!$regOpen) {
    $mode = 'login';   // 注册关了就别停在注册 tab 上
}
$bgLogin = auth_bg_html('login');
$bgReg = auth_bg_html('register');
// 注册 tab 有效且初始就在注册页时，才让它跑动画
$regActive = $mode === 'register';
$presetLogin = auth_bg_get('login')['type'] === 'preset';
$presetReg = auth_bg_get('register')['type'] === 'preset';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(page_title($mode === 'register' ? '注册' : '登录')) ?></title>
<?= site_favicon_tag() ?>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>?v=<?= e(asset_ver('app.css')) ?>">
<script>
(function(){try{var t=localStorage.getItem('kb-theme')||'system';document.documentElement.setAttribute('data-theme',t);}catch(e){}})();
</script>
</head>
<body class="login-body auth-body">

<?php /* 两个背景层都在 DOM 里，只有当前 tab 那个跑动画（data-active） */ ?>
<div class="auth-bg-stack" aria-hidden="true">
  <div class="auth-bg-layer" data-bg-layer="login"<?= $mode === 'login' ? '' : ' hidden' ?>>
    <?= str_replace('<canvas class="auth-bg auth-bg-canvas"', '<canvas class="auth-bg auth-bg-canvas" data-active="' . ($mode === 'login' ? '1' : '0') . '"', $bgLogin) ?>
  </div>
  <div class="auth-bg-layer" data-bg-layer="register"<?= $mode === 'register' ? '' : ' hidden' ?>>
    <?= str_replace('<canvas class="auth-bg auth-bg-canvas"', '<canvas class="auth-bg auth-bg-canvas" data-active="' . ($regActive ? '1' : '0') . '"', $bgReg) ?>
  </div>
</div>

<?= github_corner_link('fixed') ?>

<div class="auth-stage">
  <div class="auth-card" id="authCard">

    <?php /* 左侧品牌栏：16:9 宽屏时才有空间，窄屏隐藏（见 CSS） */ ?>
    <aside class="auth-aside">
      <div class="auth-aside-in">
        <div class="auth-brand">
          <?= site_brand_mark() ?>
          <div>
            <div class="auth-brand-name"><?= e(app_name()) ?></div>
            <div class="auth-brand-sub"><?= e(site_info('subtitle') !== '' ? site_info('subtitle') : '知识库系统') ?></div>
          </div>
        </div>

        <div class="auth-aside-copy">
          <h2>团队的知识，<br>应该能被随时问到。</h2>
          <p>分组隔离、中文检索、三种接口一套内容。装上就能用，数据留在自己的服务器。</p>
        </div>

        <ul class="auth-aside-list">
          <li><span class="auth-dot"></span>分组权限，谁能看哪些内容说得清</li>
          <li><span class="auth-dot"></span>中文二字组检索，不用分词也能搜准</li>
          <li><span class="auth-dot"></span>REST / MCP / OpenAPI，Agent 直接接</li>
        </ul>
      </div>
    </aside>

    <?php /* 右侧表单栏 */ ?>
    <div class="auth-main">
      <div class="auth-tabs" role="tablist" aria-label="登录或注册">
        <button type="button" class="auth-tab<?= $mode === 'login' ? ' on' : '' ?>"
                id="tabLogin" role="tab" data-tab="login"
                aria-selected="<?= $mode === 'login' ? 'true' : 'false' ?>"
                aria-controls="paneLogin">登录</button>
        <?php if ($regOpen): ?>
          <button type="button" class="auth-tab<?= $mode === 'register' ? ' on' : '' ?>"
                  id="tabRegister" role="tab" data-tab="register"
                  aria-selected="<?= $mode === 'register' ? 'true' : 'false' ?>"
                  aria-controls="paneRegister">注册</button>
        <?php endif; ?>
        <span class="auth-tab-ink" aria-hidden="true"></span>
      </div>

      <div class="auth-panes">

        <?php /* ---------- 登录面板 ---------- */ ?>
        <section class="auth-pane<?= $mode === 'login' ? ' on' : '' ?>" id="paneLogin"
                 role="tabpanel" aria-labelledby="tabLogin" data-pane="login">
          <div class="auth-mobile-brand">
            <?= site_brand_mark() ?>
            <div>
              <div class="auth-brand-name"><?= e(app_name()) ?></div>
              <div class="auth-brand-sub"><?= e(site_info('subtitle') !== '' ? site_info('subtitle') : '知识库系统') ?></div>
            </div>
          </div>

          <h1 class="auth-h1">欢迎回来</h1>
          <p class="auth-lead">用账号密码进入你的知识库。</p>

          <?php if (site_info('announce') !== ''): ?>
            <div class="site-announce"><?= nl2br(e((string)site_info('announce'))) ?></div>
          <?php endif; ?>

          <?php if ($loginErr !== ''): ?>
            <div class="flash err"><?= e($loginErr) ?></div>
          <?php endif; ?>

          <form method="post" action="<?= e(u('login')) ?>" class="auth-form" data-form="login">
            <?= csrf_field() ?>
            <label class="auth-field"><span class="auth-label">账号</span>
              <input type="text" name="username" autocomplete="username" required
                     value="<?= e(post('username')) ?>" placeholder="your-name">
            </label>
            <label class="auth-field"><span class="auth-label">密码</span>
              <input type="password" name="password" autocomplete="current-password" required
                     placeholder="••••••••">
            </label>
            <button class="btn primary block auth-submit" type="submit">登录</button>
          </form>

          <div class="auth-links">
            <?php if ($mailReady): ?>
              <a class="link" href="<?= e(u('forgot')) ?>">忘记密码</a>
            <?php endif; ?>
            <a class="link" href="<?= e(u('docs')) ?>">开发文档</a>
            <button type="button" class="link-btn" id="themeBtn">切换主题</button>
          </div>
        </section>

        <?php /* ---------- 注册面板 ---------- */ ?>
        <?php if ($regOpen): ?>
        <section class="auth-pane<?= $mode === 'register' ? ' on' : '' ?>" id="paneRegister"
                 role="tabpanel" aria-labelledby="tabRegister" data-pane="register">
          <div class="auth-mobile-brand">
            <?= site_brand_mark() ?>
            <div>
              <div class="auth-brand-name"><?= e(app_name()) ?></div>
              <div class="auth-brand-sub">创建账号</div>
            </div>
          </div>

          <h1 class="auth-h1">创建账号</h1>
          <p class="auth-lead">注册后自动给你一个私有空间，里面的内容只有你自己能看到。</p>

          <?php if ($regErr !== ''): ?>
            <div class="flash err"><?= e($regErr) ?></div>
          <?php endif; ?>

          <?php /* 滚动区只包字段，主按钮常驻底部：16:9 的高度装不下全部注册字段，
                  但如果连「注册并进入」都要滚下去才看得见，很多人会以为页面坏了。 */ ?>
          <form method="post" action="<?= e(u('register')) ?>" class="auth-form auth-form-fill" data-form="register">
            <?= csrf_field() ?>
            <div class="auth-scroll">
              <div class="auth-row">
                <label class="auth-field"><span class="auth-label">账号</span>
                  <input type="text" name="username" autocomplete="username" required
                         maxlength="32" pattern="[A-Za-z0-9_.@\-]{3,32}"
                         value="<?= e(post('username')) ?>" placeholder="3~32 位">
                </label>
                <label class="auth-field"><span class="auth-label">昵称<span class="auth-hint">留空用账号名</span></span>
                  <input type="text" name="display_name" autocomplete="nickname" maxlength="40"
                         value="<?= e(post('display_name')) ?>" placeholder="全站唯一">
                </label>
              </div>

              <?php if ($reg['mailVerify']): ?>
                <label class="auth-field"><span class="auth-label">邮箱<span class="auth-hint">收验证码，也用于找回密码</span></span>
                  <input type="email" name="email" autocomplete="email" required maxlength="190"
                         value="<?= e(post('email')) ?>">
                </label>
                <label class="auth-field"><span class="auth-label">邮箱验证码</span>
                  <div class="code-row">
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="6" required placeholder="6 位数字">
                    <button type="button" class="btn sm"
                            data-send-code="<?= e(u('mail_code')) ?>" data-purpose="register">发送验证码</button>
                  </div>
                </label>
              <?php elseif ($reg['mailOptional']): ?>
                <label class="auth-field"><span class="auth-label">邮箱<span class="auth-hint">选填，填了才能自助找回密码</span></span>
                  <input type="email" name="email" autocomplete="email" maxlength="190"
                         value="<?= e(post('email')) ?>">
                </label>
              <?php endif; ?>

              <div class="auth-row">
                <label class="auth-field"><span class="auth-label">密码</span>
                  <input type="password" name="password" autocomplete="new-password" required
                         minlength="8" placeholder="至少 8 位">
                </label>
                <label class="auth-field"><span class="auth-label">确认密码</span>
                  <input type="password" name="password2" autocomplete="new-password" required
                         minlength="8" placeholder="再输一次">
                </label>
              </div>

              <?php if (!$reg['mailVerify']): ?>
                <label class="auth-field"><span class="auth-label">验证码<b class="auth-captcha-q"><?= e($reg['question']) ?></b></span>
                  <input type="text" name="captcha" inputmode="numeric" autocomplete="off" required
                         maxlength="3" placeholder="填计算结果">
                </label>
              <?php endif; ?>
            </div>

            <div class="auth-foot">
              <button class="btn primary block auth-submit" type="submit">注册并进入</button>
              <p class="auth-note">
                密码至少 8 位、不能全是数字；同一网络每小时最多注册 <?= (int)$reg['limit'] ?> 个账号。
                <?php if ($reg['mailVerify']): ?>收不到验证码时看一眼垃圾箱。<?php endif; ?>
              </p>
            </div>
          </form>

          <div class="auth-links">
            <button type="button" class="link-btn" data-goto="login">已有账号，去登录</button>
            <button type="button" class="link-btn" id="themeBtn2">切换主题</button>
          </div>
        </section>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <?= site_footer_html() ?>
</div>

<script src="<?= e(asset('app.js')) ?>?v=<?= e(asset_ver('app.js')) ?>"></script>
<?php if ($presetLogin || $presetReg): ?>
<script src="<?= e(asset('auth-bg.js')) ?>?v=<?= e(asset_ver('auth-bg.js')) ?>"></script>
<?php endif; ?>
<script>
/* 同一张卡片内切换登录 / 注册，不换页、不改 URL（除非用户主动点带 href 的链接）。
   URL 里带 ?m=register 只为「直接打开注册」和「注册失败后回到注册 tab」，
   正常点击切换不写 history——否则后退键会变成两个 tab 之间来回跳，很烦人。 */
(function () {
  'use strict';
  var card = document.getElementById('authCard');
  if (!card) return;
  var tabs = card.querySelectorAll('.auth-tab');
  var panes = card.querySelectorAll('.auth-pane');
  var ink = card.querySelector('.auth-tab-ink');

  function moveInk(tab) {
    if (!ink || !tab) return;
    ink.style.width = tab.offsetWidth + 'px';
    ink.style.transform = 'translateX(' + tab.offsetLeft + 'px)';
  }

  function show(name, focus) {
    var i, found = false;
    for (i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-tab') === name) found = true;
    }
    if (!found) name = 'login';   // 注册关闭时只有一个 tab

    for (i = 0; i < tabs.length; i++) {
      var on = tabs[i].getAttribute('data-tab') === name;
      tabs[i].classList.toggle('on', on);
      tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) moveInk(tabs[i]);
    }
    for (i = 0; i < panes.length; i++) {
      panes[i].classList.toggle('on', panes[i].getAttribute('data-pane') === name);
    }

    // 背景跟着切：只让当前那层跑动画，另一层停掉留一帧静态，省 CPU
    var layers = document.querySelectorAll('[data-bg-layer]');
    for (i = 0; i < layers.length; i++) {
      var mine = layers[i].getAttribute('data-bg-layer') === name;
      layers[i].hidden = !mine;
      var cv = layers[i].querySelector('canvas[data-auth-bg]');
      if (cv && window.AuthBg && AuthBg.activate) AuthBg.activate(mine ? cv : null);
    }

    // 换 tab 后把焦点给第一个输入框，键盘用户不用再 Tab 一遍
    if (focus) {
      var pane = card.querySelector('.auth-pane.on');
      var first = pane && pane.querySelector('input:not([type=hidden])');
      if (first) { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }
    }
  }

  for (var i = 0; i < tabs.length; i++) {
    tabs[i].addEventListener('click', function () {
      show(this.getAttribute('data-tab'), false);
    });
    // 左右方向键在两个 tab 之间走，符合 tablist 的无障碍惯例
    tabs[i].addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      var list = Array.prototype.slice.call(tabs);
      var idx = list.indexOf(this);
      var next = list[(idx + (e.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length];
      e.preventDefault();
      next.focus();
      show(next.getAttribute('data-tab'), false);
    });
  }

  var gotos = card.querySelectorAll('[data-goto]');
  for (var g = 0; g < gotos.length; g++) {
    gotos[g].addEventListener('click', function () {
      show(this.getAttribute('data-goto'), true);
    });
  }

  window.addEventListener('resize', function () {
    var on = card.querySelector('.auth-tab.on');
    if (on) moveInk(on);
  });

  // 字体加载完宽度会变，ink 位置要重算一次，否则下划线短一截
  if (document.fonts && document.fonts.ready && document.fonts.ready.then) {
    document.fonts.ready.then(function () {
      var on = card.querySelector('.auth-tab.on');
      if (on) moveInk(on);
    });
  }

  var initial = card.querySelector('.auth-tab.on');
  moveInk(initial);

  // 注册 tab 的第二个主题按钮（两张面板各有一个，行为一致）
  var t2 = document.getElementById('themeBtn2');
  if (t2) {
    t2.addEventListener('click', function () {
      var b = document.getElementById('themeBtn');
      if (b) b.click();
    });
  }
})();
</script>
</body>
</html>
