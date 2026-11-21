<?php
/** @var array $c @var bool $ready @var bool $hasPass @var string $lastError @var string $lastErrAt
 *  @var string $lastOkAt @var int $recipients @var array $logs @var bool $phpMail */
?>
<section class="page-head">
  <div>
    <h1 class="page-title">邮件设置</h1>
    <p class="page-desc">
      配好并启用后，注册验证码、找回密码、管理员通知都会走这里配置的邮箱发出。
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('admin_system')) ?>">系统设置</a>
  </div>
</section>

<section class="panel">
  <h2 class="panel-title">当前状态</h2>
  <div class="kv">
    <div>
      <span>邮件开关</span>
      <b><?= $c['enabled'] ? '<span class="pill ok">已启用</span>' : '<span class="pill off">未启用</span>' ?></b>
    </div>
    <div>
      <span>配置是否可用</span>
      <b><?= $ready ? '<span class="pill ok">可以发信</span>' : '<span class="pill off">还不能发信</span>' ?></b>
    </div>
    <div><span>发送方式</span><b><?= $c['method'] === 'mail' ? 'PHP mail()（本机 MTA）' : 'SMTP' ?></b></div>
    <?php if ($c['method'] !== 'mail'): ?>
      <div><span>服务器</span><b class="mono tiny"><?= e(($c['host'] ?: '—') . ':' . (int)$c['port']) ?>
        · <?= e(strtoupper($c['secure'] === 'none' ? '明文' : $c['secure'])) ?></b></div>
      <div><span>SMTP 账号</span><b class="mono tiny"><?= e($c['user'] ?: '（不认证）') ?></b></div>
    <?php endif; ?>
    <div><span>发件人</span><b class="tiny"><?= e(($c['from_name'] ? $c['from_name'] . ' ' : '') . '<' . ($c['from'] ?: '未填写') . '>') ?></b></div>
    <div><span>注册需邮箱验证</span><b><?= $c['verify_register'] ? '是' : '否' ?></b></div>
    <div><span>可收通知人数</span><b><?= (int)$recipients ?> 人</b></div>
    <div><span>最近成功</span><b class="tiny"><?= e($lastOkAt ?: '—') ?></b></div>
    <div><span>最近失败</span><b class="tiny"><?= e($lastErrAt ?: '—') ?></b></div>
  </div>
  <?php if ($lastError !== ''): ?>
    <div class="flash err" style="margin-top:14px">上次失败原因：<?= e($lastError) ?></div>
  <?php endif; ?>
  <?php if (!$ready && $c['enabled']): ?>
    <div class="flash err" style="margin-top:14px">
      开关已打开，但还发不了信：请检查「发件人邮箱」是否填了合法地址；SMTP 方式下还要填服务器地址。
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="panel-title">SMTP 配置</h2>
  <form method="post" action="<?= e(u('admin_mail')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <label class="check-line">
      <input type="checkbox" name="mail_enabled" value="1" <?= $c['enabled'] ? 'checked' : '' ?>>
      启用邮件功能
    </label>
    <div class="hint">关掉后：登录页不显示「忘记密码」，注册退回算术验证码，通知也发不出去。已配置的内容会保留。</div>

    <div class="field-group">
      <div class="field-label">发送方式</div>
      <div class="radios">
        <label class="check"><input type="radio" name="mail_method" value="smtp"
          <?= $c['method'] !== 'mail' ? 'checked' : '' ?>> SMTP（推荐，通用）</label>
        <label class="check"><input type="radio" name="mail_method" value="mail"
          <?= $c['method'] === 'mail' ? 'checked' : '' ?>> PHP mail()（仅当主机自带邮件服务）</label>
      </div>
    </div>

    <div class="form row-form">
      <label class="grow">SMTP 服务器
        <input type="text" name="mail_host" maxlength="190" placeholder="smtp.qq.com"
               value="<?= e($c['host']) ?>">
      </label>
      <label class="narrow">端口
        <input type="number" name="mail_port" min="1" max="65535" value="<?= (int)$c['port'] ?>">
      </label>
      <label>加密方式
        <select name="mail_secure">
          <option value="ssl" <?= $c['secure'] === 'ssl' ? 'selected' : '' ?>>SSL（常见 465）</option>
          <option value="tls" <?= $c['secure'] === 'tls' ? 'selected' : '' ?>>STARTTLS（常见 587）</option>
          <option value="none" <?= $c['secure'] === 'none' ? 'selected' : '' ?>>不加密（25，内网中继）</option>
        </select>
      </label>
    </div>

    <div class="form row-form">
      <label class="grow">SMTP 账号
        <input type="text" name="mail_user" maxlength="190" autocomplete="off"
               placeholder="通常是完整邮箱地址" value="<?= e($c['user']) ?>">
      </label>
      <label class="grow">SMTP 密码 / 授权码
        <input type="password" name="mail_pass" autocomplete="new-password"
               placeholder="<?= $hasPass ? '已保存，留空表示不修改' : 'QQ/163 等要用授权码，不是登录密码' ?>">
      </label>
    </div>
    <?php if ($hasPass): ?>
      <label class="check-line">
        <input type="checkbox" name="mail_pass_clear" value="1">
        清空已保存的密码
      </label>
    <?php endif; ?>

    <div class="form row-form">
      <label class="grow">发件人邮箱
        <input type="email" name="mail_from" maxlength="190" placeholder="noreply@yourdomain.com"
               value="<?= e($c['from']) ?>">
      </label>
      <label class="grow">发件人名称
        <input type="text" name="mail_from_name" maxlength="60" placeholder="例如：知识库"
               value="<?= e($c['from_name']) ?>">
      </label>
    </div>
    <div class="hint">
      多数邮箱服务商要求发件人地址与 SMTP 账号一致（或已在其后台验证过），否则会被拒收。
    </div>

    <label class="check-line">
      <input type="checkbox" name="mail_no_verify" value="1" <?= $c['no_verify'] ? 'checked' : '' ?>>
      跳过 TLS 证书校验（仅调试用）
    </label>
    <div class="hint">自建邮件服务器用自签证书、或者 PHP 报证书错误时才勾。正式环境别勾，否则容易被中间人。</div>

    <label class="check-line">
      <input type="checkbox" name="mail_verify_register" value="1" <?= $c['verify_register'] ? 'checked' : '' ?>>
      注册必须验证邮箱
    </label>
    <div class="hint">开启后注册要填邮箱 + 验证码，账号天然带一个已验证邮箱；关闭则仍用算术验证码，邮箱选填。</div>

    <label class="check-line">
      <input type="checkbox" name="mail_welcome" value="1" <?= $c['welcome'] ? 'checked' : '' ?>>
      注册成功发欢迎邮件
    </label>

    <div class="form-actions">
      <button class="btn primary" type="submit">保存设置</button>
    </div>
  </form>
</section>

<div class="two-col">
  <section class="panel">
    <h2 class="panel-title">发送测试邮件</h2>
    <form method="post" action="<?= e(u('admin_mail')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <label>收件邮箱
        <input type="email" name="to" maxlength="190" required
               value="<?= e((string)(current_user()['email'] ?? '')) ?>" placeholder="收件地址">
      </label>
      <div class="hint">先保存上面的配置，再来发测试信。失败原因会写在下方的发送记录里。</div>
      <div class="form-actions">
        <button class="btn" type="submit">发送测试</button>
      </div>
    </form>
  </section>

  <section class="panel">
    <h2 class="panel-title">群发通知</h2>
    <form method="post" action="<?= e(u('admin_mail')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="notify">
      <label>主题
        <input type="text" name="subject" maxlength="120" required placeholder="例如：本周知识库更新">
      </label>
      <label>正文
        <textarea name="body" rows="6" required
                  placeholder="空行分段，纯文本即可，会自动排版成邮件。"></textarea>
      </label>
      <div class="hint">
        会发给 <b><?= (int)$recipients ?></b> 位「邮箱已验证且没关通知」的用户，每次最多 200 人。
        正文里不要放验证码之类的隐私信息。
      </div>
      <div class="form-actions">
        <button class="btn primary" type="submit"
                onclick="return confirm('确定给 <?= (int)$recipients ?> 位用户群发这封通知？')">发送通知</button>
      </div>
    </form>
  </section>
</div>

<section class="panel">
  <details class="fold">
    <summary>发送记录 <span class="fold-note">最近 <?= count($logs) ?> 条 · 含失败原因</span></summary>
    <div class="fold-body">
  <?php if (!$logs): ?>
    <div class="empty">还没有发送记录。配好后先发一封测试邮件。</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>时间</th><th>收件人</th><th>类型</th><th>主题</th><th>结果</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td class="tiny mono"><?= e($l['created_at']) ?></td>
            <td class="tiny mono"><?= e($l['to_email']) ?></td>
            <td class="tiny"><?= e($l['kind']) ?></td>
            <td class="tiny"><?= e(mb_substr((string)$l['subject'], 0, 40, 'UTF-8')) ?></td>
            <td class="tiny">
              <?php if ((int)$l['ok'] === 1): ?>
                <span class="pill ok">成功</span>
              <?php else: ?>
                <span class="pill off">失败</span>
                <div class="muted tiny"><?= e($l['error']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" action="<?= e(u('admin_mail')) ?>" class="inline-form" style="margin-top:12px"
          onsubmit="return confirm('清空全部发送记录？不影响任何配置。')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="log_clear">
      <button class="link danger" type="submit">清空记录</button>
    </form>
  <?php endif; ?>
    </div>
  </details>
</section>

<section class="panel">
  <details class="fold">
    <summary>常见配置 <span class="fold-note">各邮箱服务器参数 · 报错代码对照</span></summary>
    <div class="fold-body">
  <div class="prose small">
    <table class="table">
      <thead><tr><th>邮箱</th><th>服务器</th><th>端口 / 加密</th><th>密码填什么</th></tr></thead>
      <tbody>
        <tr><td>QQ 邮箱</td><td class="mono">smtp.qq.com</td><td>465 / SSL</td><td>设置 → 账户里生成的「授权码」</td></tr>
        <tr><td>163 邮箱</td><td class="mono">smtp.163.com</td><td>465 / SSL</td><td>客户端授权密码</td></tr>
        <tr><td>阿里云企业邮</td><td class="mono">smtp.qiye.aliyun.com</td><td>465 / SSL</td><td>邮箱登录密码</td></tr>
        <tr><td>腾讯企业邮</td><td class="mono">smtp.exmail.qq.com</td><td>465 / SSL</td><td>邮箱登录密码或专用密码</td></tr>
        <tr><td>Gmail</td><td class="mono">smtp.gmail.com</td><td>587 / STARTTLS</td><td>应用专用密码（需开两步验证）</td></tr>
      </tbody>
    </table>
    <p class="muted">
      发送失败先看记录里的错误：<code>535</code> 多半是密码填了登录密码而不是授权码；
      <code>连接超时</code> 多半是主机商封了外发 SMTP 端口，换 587 或改用企业邮箱；
      <code>550</code> 是收件地址或发件地址被拒。
    </p>
    <p class="muted">本机是否支持 PHP mail()：<b><?= $phpMail ? '支持' : '不支持' ?></b>（不支持就只能用 SMTP）。</p>
  </div>
    </div>
  </details>
</section>
