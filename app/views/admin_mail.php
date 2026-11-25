<?php
/** @var array $c @var bool $ready @var bool $hasPass @var bool $hasApiKey @var string $lastError
 *  @var string $lastErrAt @var string $lastOkAt @var int $recipients @var array $logs @var bool $phpMail */
$isResend = $c['method'] !== 'smtp' && $c['method'] !== 'mail';
$methodName = $isResend ? 'Resend（HTTPS API）' : ($c['method'] === 'mail' ? 'PHP mail()（本机 MTA）' : 'SMTP');
?>
<section class="page-head">
  <div>
    <h1 class="page-title">邮件设置</h1>
    <p class="page-desc">
      填三样就能发信：API Key、发件人邮箱、发件人名称。注册验证码、找回密码、系统通知都走这条通道。
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
    <div><span>发送通道</span><b><?= e($methodName) ?></b></div>
    <?php if ($isResend): ?>
      <div><span>API Key</span><b class="mono tiny"><?= $hasApiKey ? e('已保存 · ' . (str_starts_with($c['api_key'], 're_') ? 're_…' : '格式待确认')) : '（未填写）' ?></b></div>
    <?php elseif ($c['method'] === 'smtp'): ?>
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
      开关已打开，但还发不了信：<?= $isResend
        ? '请填写 Resend API Key，并确认「发件人邮箱」是合法地址。'
        : '请检查「发件人邮箱」是否填了合法地址；SMTP 方式下还要填服务器地址。' ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="panel-title">发信配置</h2>
  <form method="post" action="<?= e(u('admin_mail')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <label class="check-line">
      <input type="checkbox" name="mail_enabled" value="1" <?= $c['enabled'] ? 'checked' : '' ?>>
      启用邮件功能
    </label>
    <div class="hint">关掉后：登录页不显示「忘记密码」，注册退回算术验证码，通知也发不出去。已配置的内容会保留。</div>

    <div class="field-group">
      <div class="field-label">发送通道</div>
      <div class="radios">
        <label class="check"><input type="radio" name="mail_method" value="resend"
          <?= $isResend ? 'checked' : '' ?>> Resend（推荐 · 只需 API Key，走 443 不受主机封端口影响）</label>
        <label class="check"><input type="radio" name="mail_method" value="smtp"
          <?= $c['method'] === 'smtp' ? 'checked' : '' ?>> SMTP（已有企业邮箱时用）</label>
        <label class="check"><input type="radio" name="mail_method" value="mail"
          <?= $c['method'] === 'mail' ? 'checked' : '' ?>> PHP mail()（仅当主机自带邮件服务）</label>
      </div>
    </div>

    <!-- ===== Resend：三字段 ===== -->
    <div class="mail-block" data-mail-pane="resend" <?= $isResend ? '' : 'hidden' ?>>
      <div class="form row-form">
        <label class="grow">Resend API Key
          <input type="password" name="mail_api_key" autocomplete="new-password"
                 placeholder="<?= $hasApiKey ? '已保存，留空表示不修改' : 're_ 开头，从 resend.com/api-keys 复制' ?>">
        </label>
      </div>
      <?php if ($hasApiKey): ?>
        <label class="check-line">
          <input type="checkbox" name="mail_api_key_clear" value="1">
          清空已保存的 API Key
        </label>
      <?php endif; ?>
      <div class="hint">
        到 <code>resend.com/api-keys</code> 点「Create API Key」，权限选 <b>Sending access</b> 即可。
        整行 <code>Authorization: Bearer re_…</code> 粘进来也能自动去掉前缀，不用手工清理。
      </div>
      <div class="hint">
        <b>首次使用先看这条：</b>没验证自有域名时，Resend 只允许发给<b>你注册 Resend 用的那个邮箱</b>，
        发件人必须填 <code>onboarding@resend.dev</code>。要给任意用户发信，需在
        <code>resend.com/domains</code> 添加并验证自己的域名（配 SPF/DKIM），
        然后把下面「发件人邮箱」改成该域名下的地址，例如 <code>noreply@你的域名.com</code>。
      </div>
    </div>

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
      Resend 通道下，这个地址的域名必须是已在 Resend 验证过的（或 <code>resend.dev</code> 测试域名）。
      SMTP 通道下，多数服务商要求它与 SMTP 账号一致。
    </div>

    <!-- ===== SMTP：高级选项，折叠 ===== -->
    <details class="fold mail-adv" data-mail-pane="smtp" <?= $c['method'] === 'smtp' ? 'open' : '' ?>>
      <summary>SMTP 服务器参数 <span class="fold-note">选「SMTP」时必填</span></summary>
      <div class="fold-body">
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
            清空已保存的 SMTP 密码
          </label>
        <?php endif; ?>

        <label class="check-line">
          <input type="checkbox" name="mail_no_verify" value="1" <?= $c['no_verify'] ? 'checked' : '' ?>>
          跳过 TLS 证书校验（仅调试用）
        </label>
        <div class="hint">自建邮件服务器用自签证书、或者 PHP 报证书错误时才勾。正式环境别勾，否则容易被中间人。</div>
      </div>
    </details>

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
      <div class="hint">
        先保存上面的配置，再来发测试信。失败原因会写在下方的发送记录里。
        <?php if ($isResend && !$hasApiKey): ?>
          <b>现在还没填 API Key，会直接失败。</b>
        <?php endif; ?>
      </div>
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
    <summary>常见问题与参数对照 <span class="fold-note">Resend 报错 · SMTP 服务商参数</span></summary>
    <div class="fold-body">
  <div class="prose small">
    <h4 style="margin-top:0">Resend 常见报错</h4>
    <table class="table">
      <thead><tr><th style="width:200px">报错</th><th>原因与处理</th></tr></thead>
      <tbody>
        <tr>
          <td>You can only send testing emails to your own email address</td>
          <td>还在用测试域名。要么把收件人改成你注册 Resend 的邮箱，要么去验证自己的域名并换发件人。</td>
        </tr>
        <tr>
          <td>domain is not verified</td>
          <td>发件人域名没在 Resend 验证通过。到 <code>resend.com/domains</code> 配好 SPF/DKIM，等到状态 Verified。</td>
        </tr>
        <tr>
          <td>API key is invalid</td>
          <td>Key 不对。重新复制完整的 <code>re_</code> 开头字符串，注意别多带空格或换行。</td>
        </tr>
        <tr>
          <td>这一天的发信额度已用完</td>
          <td>免费版每天 100 封、每月 3000 封，UTC 0 点重置；不够就升级套餐。</td>
        </tr>
      </tbody>
    </table>

    <h4>SMTP 服务商参数（备用通道）</h4>
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
      SMTP 失败先看记录里的错误：<code>535</code> 多半是密码填了登录密码而不是授权码；
      <code>连接超时</code> 多半是主机商封了外发 SMTP 端口 —— 这种情况直接用 Resend 通道即可绕开；
      <code>550</code> 是收件地址或发件地址被拒。
    </p>
    <p class="muted">本机是否支持 PHP mail()：<b><?= $phpMail ? '支持' : '不支持' ?></b>。</p>
  </div>
    </div>
  </details>
</section>
