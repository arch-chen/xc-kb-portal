<?php
/** @var array $u @var ?array $space @var bool $ready @var array $groups */
$email = (string)($u['email'] ?? '');
$verified = ($u['email_verified_at'] ?? null) !== null && $email !== '';
$notify = (int)($u['notify_mail'] ?? 1) === 1;
?>
<section class="page-head">
  <div>
    <h1 class="page-title">账号资料</h1>
    <p class="page-desc">改昵称、改密码、绑定邮箱都在这一页。账单（充值、订单、AI 消耗）与 API Key 在上方切换。</p>
  </div>
</section>

<section class="panel">
  <h2 class="panel-title">账号信息</h2>
  <div class="kv">
    <div><span>账号</span><b class="mono"><?= e($u['username']) ?></b></div>
    <div><span>昵称</span><b><?= e($u['display_name'] ?: $u['username']) ?></b></div>
    <div><span>角色</span><b><?= $u['role'] === 'admin' ? '管理员 ' . tip_text('后台全通；前台与普通用户同权同价，一样要自己建分组、一样按套餐计费。', '', '角色说明') : '普通用户' ?></b></div>
    <div>
      <span>邮箱</span>
      <b>
        <?php if ($email === ''): ?>
          <span class="muted">未绑定</span>
        <?php elseif ($verified): ?>
          <?= e($email) ?> <span class="pill ok">已验证</span>
        <?php else: ?>
          <?= e($email) ?> <span class="pill off">未验证</span>
        <?php endif; ?>
      </b>
    </div>
    <div><span>私有空间</span><b><?= $space ? e($space['name']) : '—' ?></b></div>
    <div><span>可访问分组</span><b><?= count($groups) ?> 个</b></div>
    <div><span>注册时间</span><b class="tiny"><?= e($u['created_at'] ?: '—') ?></b></div>
    <div><span>最后登录</span><b class="tiny"><?= e($u['last_login_at'] ?: '—') ?></b></div>
  </div>
</section>

<div class="two-col">
  <section class="panel">
    <h2 class="panel-title">基本资料</h2>
    <form method="post" action="<?= e(u('account')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <label><?= field_label('昵称', '昵称全站唯一，不能和别人重复。它同时是你所有私有知识库的名称前缀（例如「' . nick_of($u) . ' · 售后话术」），改完会自动同步过去。') ?>
        <input type="text" name="display_name" maxlength="40"
               value="<?= e($u['display_name'] ?: $u['username']) ?>">
      </label>
      <label class="check-line">
        <input type="checkbox" name="notify_mail" value="1" <?= $notify ? 'checked' : '' ?>>
        接收站内通知邮件<?= label_tip('关掉后，管理员群发通知时不会发到你的邮箱；验证码、找回密码这类事务邮件不受影响。') ?>
      </label>
      <div class="form-actions">
        <button class="btn primary" type="submit">保存资料</button>
      </div>
    </form>
  </section>

  <section class="panel">
    <?= panel_title('修改密码', '至少 8 位，不能全是数字。改完不会踢掉当前登录状态，其它设备上的会话也不会失效。') ?>
    <form method="post" action="<?= e(u('account')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label>当前密码
        <input type="password" name="old_password" autocomplete="current-password" required>
      </label>
      <label>新密码
        <input type="password" name="new_password" autocomplete="new-password" required minlength="8">
      </label>
      <label>再输一次新密码
        <input type="password" name="new_password2" autocomplete="new-password" required minlength="8">
      </label>
      <div class="form-actions">
        <button class="btn primary" type="submit">修改密码</button>
      </div>
    </form>
  </section>
</div>

<?php if ($ready): ?>
<section class="panel">
  <h2 class="panel-title">邮箱</h2>
  <?php if (!$verified && $email !== ''): ?>
    <div class="flash err">
      当前邮箱 <b><?= e($email) ?></b> 还没有验证，收不到通知、也不能用来找回密码。
      在下面重新走一次验证码即可完成验证。
    </div>
  <?php endif; ?>
  <form method="post" action="<?= e(u('account')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="email">
    <label><?= field_label($verified ? '更换邮箱' : '绑定邮箱', '验证码会发到新地址。验证通过后才算绑定成功——这样别人用别人的邮箱也注册不了你的账号。') ?>
      <input type="email" name="email" id="accEmail" maxlength="190" required
             autocomplete="email" placeholder="you@example.com"
             value="<?= e($email) ?>">
    </label>
    <label>验证码
      <div class="code-row">
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
               maxlength="6" required placeholder="6 位数字">
        <button type="button" class="btn sm"
                data-send-code="<?= e(u('mail_code')) ?>" data-purpose="verify"
                data-email-from="#accEmail">发送验证码</button>
      </div>
    </label>
    <div class="form-actions">
      <button class="btn primary" type="submit">验证并保存</button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($ready && $verified): ?>
<section class="panel">
  <h2 class="panel-title">忘记当前密码？用邮箱验证码改</h2>
  <form method="post" action="<?= e(u('account')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password_code">
    <label>验证码
      <div class="code-row">
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
               maxlength="6" required placeholder="6 位数字">
        <button type="button" class="btn sm"
                data-send-code="<?= e(u('mail_code')) ?>" data-purpose="change"
                data-email="<?= e($email) ?>">发送验证码</button>
      </div>
    </label>
    <label>新密码
      <input type="password" name="new_password" autocomplete="new-password" required minlength="8">
    </label>
    <label>再输一次
      <input type="password" name="new_password2" autocomplete="new-password" required minlength="8">
    </label>
    <div class="hint muted tiny">验证码发到 <b><?= e($email) ?></b>，15 分钟内有效。</div>
    <div class="form-actions">
      <button class="btn" type="submit">用验证码修改密码</button>
    </div>
  </form>
</section>
<?php endif; ?>
