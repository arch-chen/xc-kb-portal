<?php
declare(strict_types=1);

/**
 * 邮件能力：配置、模板、验证码、通知。
 *
 * 设计要点：
 *   - 配置全部落在 settings 表（mail_*），SMTP 密码用 secret_encrypt() 加密存，
 *     单拿走数据库文件看不到密码，得同时有 config.php 里的 pepper 才能解。
 *   - 验证码只存 HMAC，不存明文；一次性使用，错 5 次作废，15 分钟过期。
 *   - 邮件开关关掉时，注册/找回密码自动退回原来的算术验证码或提示联系管理员。
 */

const MAIL_CODE_TTL = 900;        // 验证码有效期（秒）
const MAIL_CODE_MAX_TRIES = 5;    // 同一个码最多试几次
const MAIL_CODE_COOLDOWN = 60;    // 同一邮箱两次发码的最小间隔（秒）

/* ---------------- 配置 ---------------- */

function mail_cfg(bool $reset = false): array
{
    static $c = null;
    if ($c !== null && !$reset) {
        return $c;
    }
    $c = [
        'enabled'  => (string)setting_get('mail_enabled', '0') === '1',
        'method'   => (string)(setting_get('mail_method', 'resend') ?: 'resend'),
        'host'     => trim((string)setting_get('mail_host', '')),
        'port'     => (int)setting_get('mail_port', '465'),
        'secure'   => (string)(setting_get('mail_secure', 'ssl') ?: 'ssl'),
        'user'     => trim((string)setting_get('mail_user', '')),
        'pass'     => secret_decrypt((string)setting_get('mail_pass', '')),
        'api_key'  => secret_decrypt((string)setting_get('mail_api_key', '')),
        'from'     => trim((string)setting_get('mail_from', '')),
        'from_name' => trim((string)setting_get('mail_from_name', '')),
        'no_verify' => (string)setting_get('mail_no_verify', '0') === '1',
        'verify_register' => (string)setting_get('mail_verify_register', '1') === '1',
        'welcome'  => (string)setting_get('mail_welcome', '1') === '1',
    ];
    return $c;
}

/** 开关打开（不代表配置完整） */
function mail_enabled(): bool
{
    return mail_cfg()['enabled'];
}

/**
 * 真正能发信：开关打开 + 发件人合法 + 所选通道的凭证齐备。
 *
 * 三种通道的必备项不同：
 *   resend —— API Key（走 HTTPS，不受主机封端口影响）
 *   smtp   —— 服务器地址
 *   mail   —— 什么都不用，交给本机 MTA
 */
function mail_ready(): bool
{
    $c = mail_cfg();
    if (!$c['enabled'] || !filter_var($c['from'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if ($c['method'] === 'mail') {
        return true;
    }
    if ($c['method'] === 'resend') {
        return $c['api_key'] !== '';
    }
    return $c['host'] !== '';
}

/** 注册是否强制邮箱验证（邮件没配好时自动不强制，避免没人能注册） */
function mail_register_verify(): bool
{
    return mail_ready() && mail_cfg()['verify_register'];
}

function mail_email_norm(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function mail_user_by_email(string $email): ?array
{
    $e = mail_email_norm($email);
    if ($e === '') {
        return null;
    }
    return one('SELECT * FROM ' . tbl('users') . ' WHERE LOWER(email) = ? LIMIT 1', [$e]);
}

/* ---------------- 发送 ---------------- */

/**
 * 发一封邮件（HTML + 自动生成的纯文本）。失败不抛异常，把原因放在 error 里。
 *
 * @return array{ok:bool,error:string}
 */
function mail_send(string $to, string $subject, string $html, string $text = '',
                   string $kind = 'other', ?int $uid = null): array
{
    $c = mail_cfg();
    if (!$c['enabled']) {
        return ['ok' => false, 'error' => '邮件功能未启用'];
    }
    if (!filter_var($c['from'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '「发件人邮箱」没有填写或不合法'];
    }
    $to = mail_email_norm($to);

    if ($c['method'] === 'mail') {
        $r = mail_send_php_mail($c, $to, $subject, $html, $text);
    } elseif ($c['method'] === 'resend') {
        $r = resend_send($c, $to, $subject, $html, $text);
    } else {
        $r = smtp_send($c, $to, $subject, $html, $text);
    }

    mail_log_write($to, $subject, $kind, $r['ok'], $r['error'], $uid);
    if (!$r['ok']) {
        setting_set('mail_last_error', mb_substr($r['error'], 0, 400));
        setting_set('mail_last_error_at', now_str());
    } else {
        setting_set('mail_last_ok_at', now_str());
    }
    return ['ok' => $r['ok'], 'error' => (string)$r['error']];
}

/** 共享主机没有 SMTP 权限时的退路：交给本机 sendmail / PHP mail() */
function mail_send_php_mail(array $c, string $to, string $subject, string $html, string $text): array
{
    if (!function_exists('mail')) {
        return ['ok' => false, 'error' => 'PHP 的 mail() 不可用'];
    }
    if ($text === '') {
        $text = trim(strip_tags($html));
    }
    $enc = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $b = 'alt_' . bin2hex(random_bytes(8));
    $body = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text), 76, "\r\n")
        . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html), 76, "\r\n")
        . "--{$b}--\r\n";
    $headers = [
        'From: ' . ($c['from_name'] !== '' ? $enc($c['from_name']) . ' ' : '') . '<' . $c['from'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
        'X-Mailer: kb-portal',
    ];
    $ok = @mail($to, $enc($subject), $body, implode("\r\n", $headers));
    return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'mail() 返回失败（本机没有配置 MTA，建议改用 SMTP）'];
}

function mail_log_write(string $to, string $subject, string $kind, bool $ok, string $error, ?int $uid): void
{
    try {
        insert_row('mail_log', [
            'to_email'   => mb_substr($to, 0, 190),
            'user_id'    => $uid,
            'subject'    => mb_substr($subject, 0, 200),
            'kind'       => mb_substr($kind, 0, 32),
            'ok'         => $ok ? 1 : 0,
            'error'      => mb_substr($error, 0, 480),
            'created_at' => now_str(),
        ]);
        // 只留最近 500 条，避免日志表无限增长
        if (random_int(1, 25) === 1) {
            $keep = (int)val('SELECT MAX(id) FROM ' . tbl('mail_log'), [], 0) - 500;
            if ($keep > 0) {
                q('DELETE FROM ' . tbl('mail_log') . ' WHERE id < ?', [$keep]);
            }
        }
    } catch (Throwable $e) {
        // 日志写失败不能影响发信结果
    }
}

/* ---------------- 模板 ---------------- */

/**
 * 统一的邮件外观（紫色头部 + 白色卡片）。邮件客户端对 CSS 支持极差，
 * 所以全部用行内样式 + 表格/块级布局，不依赖 <style> 标签。
 *
 * @param array<int,string> $paras 正文段落（已 HTML 转义）
 * @param array<int,array{0:string,1:string}> $rows 键值行
 */
function mail_html(string $title, string $intro, array $paras = [], ?string $code = null,
                   array $rows = [], string $footNote = ''): string
{
    $site = e(app_name());
    $accent = '#4f46e5';

    $h = '<div style="margin:0;padding:24px;background:#f4f4f5;'
        . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,'
        . '\'PingFang SC\',\'Microsoft YaHei\',sans-serif;color:#18181b;">';
    $h .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;'
        . 'overflow:hidden;box-shadow:0 8px 30px rgba(15,23,42,.10);">';

    // 头部：站点名（小字）+ 标题
    $h .= '<div style="background:' . $accent . ';color:#ffffff;padding:28px 32px;">'
        . '<div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;'
        . 'opacity:.78;margin-bottom:10px;font-weight:600;">' . $site . '</div>'
        . '<h1 style="margin:0;font-size:24px;line-height:1.25;font-weight:700;color:#ffffff;">'
        . e($title) . '</h1></div>';

    // 正文
    $h .= '<div style="padding:32px;font-size:15px;line-height:1.7;color:#18181b;">';
    $h .= '<p style="margin:0 0 14px;">' . e($intro) . '</p>';

    if ($code !== null) {
        $h .= '<p style="margin:0 0 8px;">您的验证码是：</p>'
            . '<p style="margin:0 0 18px;font-size:32px;font-weight:700;letter-spacing:8px;'
            . 'text-align:center;font-family:Consolas,Menlo,monospace;color:#18181b;">'
            . e($code) . '</p>'
            . '<p style="margin:0 0 14px;">验证码将在 <strong>'
            . (int)(MAIL_CODE_TTL / 60) . '</strong> 分钟后失效。</p>';
    }

    foreach ($paras as $p) {
        $h .= '<p style="margin:0 0 14px;">' . $p . '</p>';
    }

    if ($rows) {
        $h .= '<table style="width:100%;border-collapse:collapse;margin:18px 0;font-size:14px;">';
        foreach ($rows as $r) {
            $h .= '<tr>'
                . '<td style="padding:8px 0;color:#71717a;width:110px;vertical-align:top;border-bottom:1px solid #f4f4f5;">'
                . e($r[0]) . '</td>'
                . '<td style="padding:8px 0;color:#18181b;border-bottom:1px solid #f4f4f5;">'
                . e($r[1]) . '</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '</div>';

    // 页脚
    $h .= '<div style="padding:18px 32px;background:#fafafa;color:#a1a1aa;font-size:12px;'
        . 'line-height:1.7;">'
        . ($footNote !== '' ? e($footNote) . '<br>' : '')
        . 'This email was sent by ' . $site . '. Please do not reply directly.</div>';

    $h .= '</div></div>';
    return $h;
}

/**
 * 验证码邮件（按用途换措辞）。
 *
 * $name 是收件人称呼：有用户名就用它，没有就用邮箱前缀，都没有就退成「您好」。
 * 模板里的 {{recipient_name}} / {{verification_code}} / {{expires_in_minutes}} / {{site_name}}
 * 四个占位全部由这里填好，不再依赖外部模板引擎。
 */
function mail_code_mail(string $purpose, string $code, string $name = '', string $email = ''): array
{
    $site = app_name();

    // 称呼：用户名 → 邮箱前缀 → 「您好」
    $who = trim($name);
    if ($who === '' && $email !== '') {
        $at = strpos($email, '@');
        $who = $at !== false ? substr($email, 0, $at) : $email;
    }
    if ($who === '') {
        $who = '您好';
    }

    switch ($purpose) {
        case 'register':
            $subject = $site . ' 注册验证码';
            $title = '邮箱验证码';
            $intro = $who . '，您好：';
            $paras = ['把上面的验证码填回注册页面即可完成注册。如果不是您本人操作，请忽略此邮件，邮箱不会被注册。'];
            break;
        case 'reset':
            $subject = $site . ' 重置密码验证码';
            $title = '重置密码';
            $intro = $who . '，您好：';
            $paras = ['验证码填回页面后即可设置新密码。如果不是您本人操作，请立即修改密码并检查账号安全。'];
            break;
        case 'change':
            $subject = $site . ' 修改密码验证码';
            $title = '修改密码';
            $intro = $who . '，您好：';
            $paras = ['如果这不是您本人的操作，说明有人已经登录了您的账号，请立刻联系管理员。'];
            break;
        default:
            $subject = $site . ' 邮箱验证码';
            $title = '邮箱验证码';
            $intro = $who . '，您好：';
            $paras = ['验证成功后，账号通知、找回密码都会用到这个邮箱。'];
            break;
    }

    $html = mail_html($title, $intro, $paras, $code);
    $text = $title . "\n\n" . $intro . "\n\n您的验证码是：" . $code . "\n\n"
        . '验证码将在 ' . (int)(MAIL_CODE_TTL / 60) . ' 分钟后失效。'
        . "\n\n如果不是您本人操作，请忽略此邮件。"
        . "\n\nThis email was sent by {$site}. Please do not reply directly.";
    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/** 通知邮件（纯文本正文按空行分段） */
function mail_notice_mail(string $subject, string $body, array $rows = []): array
{
    $paras = [];
    foreach (preg_split('/\n\s*\n/', trim($body)) ?: [] as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $paras[] = nl2br(e($p));
    }
    $html = mail_html($subject, '来自 ' . app_name() . ' 的一则通知：', $paras, null, $rows);
    $text = $subject . "\n\n" . trim($body);
    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

/* ---------------- 验证码 ---------------- */

function mail_code_hash(string $email, string $purpose, string $code): string
{
    $pepper = (string)(cfg()['security']['pepper'] ?? '');
    return hash_hmac('sha256', $purpose . '|' . $email . '|' . $code, $pepper);
}

/**
 * 签发一个验证码并发邮件。
 *
 * @return array{ok:bool,error:string,wait:int,ttl:int}
 */
function mail_code_issue(string $email, string $purpose, int $uid = 0): array
{
    $email = mail_email_norm($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '邮箱格式不对', 'wait' => 0, 'ttl' => 0];
    }
    if (!mail_ready()) {
        return ['ok' => false, 'error' => '管理员还没有配置好邮件服务', 'wait' => 0, 'ttl' => 0];
    }

    $tbl = tbl('email_codes');
    $last = one("SELECT created_at FROM {$tbl} WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1",
        [$email, $purpose]);
    if ($last && $last['created_at']) {
        $gap = time() - (int)strtotime((string)$last['created_at']);
        if ($gap < MAIL_CODE_COOLDOWN) {
            $wait = MAIL_CODE_COOLDOWN - $gap;
            return ['ok' => false, 'error' => '发送太频繁，请 ' . $wait . ' 秒后再试', 'wait' => $wait, 'ttl' => 0];
        }
    }

    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $n = (int)val("SELECT COUNT(*) FROM {$tbl} WHERE email = ? AND created_at >= ?", [$email, $hourAgo], 0);
    if ($n >= 6) {
        return ['ok' => false, 'error' => '这个邮箱一小时内收得太多了，请稍后再试', 'wait' => 300, 'ttl' => 0];
    }
    // 按 IP 再限一道，防止换邮箱刷
    if (!throttle_hit('mailcode', 15, 3600)) {
        return ['ok' => false, 'error' => '当前网络请求太频繁，请稍后再试', 'wait' => 300, 'ttl' => 0];
    }

    $code = (string)random_int(100000, 999999);
    $hash = mail_code_hash($email, $purpose, $code);

    // 同用途的旧码作废，保证「最后一次发的那个」才有效
    q("DELETE FROM {$tbl} WHERE email = ? AND purpose = ? AND used_at IS NULL", [$email, $purpose]);
    $id = insert_row('email_codes', [
        'email'      => $email,
        'purpose'    => $purpose,
        'code_hash'  => $hash,
        'uid'        => $uid > 0 ? $uid : null,
        'tries'      => 0,
        'expires_at' => date('Y-m-d H:i:s', time() + MAIL_CODE_TTL),
        'used_at'    => null,
        'ip'         => client_ip(),
        'created_at' => now_str(),
    ]);

    $tpl = mail_code_mail($purpose, $code, '', $email);
    $r = mail_send($email, $tpl['subject'], $tpl['html'], $tpl['text'], 'code:' . $purpose, $uid ?: null);
    if (!$r['ok']) {
        // 没发出去就不留码，避免用户拿着一个收不到的码反复试
        q("DELETE FROM {$tbl} WHERE id = ?", [$id]);
        return ['ok' => false, 'error' => '邮件发送失败：' . $r['error'], 'wait' => 0, 'ttl' => 0];
    }
    return ['ok' => true, 'error' => '', 'wait' => MAIL_CODE_COOLDOWN, 'ttl' => MAIL_CODE_TTL];
}

/**
 * 校验验证码。成功即作废；失败累加次数，超过上限直接判定过期。
 *
 * @return array{ok:bool,error:string,uid:int}
 */
function mail_code_verify(string $email, string $purpose, string $code): array
{
    $email = mail_email_norm($email);
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if ($code === '' || strlen($code) !== 6) {
        return ['ok' => false, 'error' => '请输入 6 位数字验证码', 'uid' => 0];
    }
    $tbl = tbl('email_codes');
    $row = one("SELECT * FROM {$tbl} WHERE email = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1",
        [$email, $purpose]);
    if (!$row) {
        return ['ok' => false, 'error' => '还没有获取验证码，或验证码已使用', 'uid' => 0];
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        return ['ok' => false, 'error' => '验证码已过期，请重新获取', 'uid' => 0];
    }
    $tries = (int)$row['tries'];
    if ($tries >= MAIL_CODE_MAX_TRIES) {
        return ['ok' => false, 'error' => '错误次数过多，请重新获取验证码', 'uid' => 0];
    }
    if (!hash_equals((string)$row['code_hash'], mail_code_hash($email, $purpose, $code))) {
        q("UPDATE {$tbl} SET tries = tries + 1 WHERE id = ?", [(int)$row['id']]);
        $left = MAIL_CODE_MAX_TRIES - ($tries + 1);
        return [
            'ok' => false,
            'error' => '验证码不对' . ($left > 0 ? '，还能再试 ' . $left . ' 次' : '，请重新获取'),
            'uid' => 0,
        ];
    }
    q("UPDATE {$tbl} SET used_at = ?, tries = tries + 1 WHERE id = ?", [now_str(), (int)$row['id']]);
    return ['ok' => true, 'error' => '', 'uid' => (int)($row['uid'] ?? 0)];
}

/* ---------------- 通知 ---------------- */

/** 能收通知的人：启用中 + 有邮箱 + 邮箱已验证 + 没关掉通知 */
function mail_recipients(int $limit = 500): array
{
    return all('SELECT id, username, display_name, email FROM ' . tbl('users') . "
                WHERE status = 1 AND email IS NOT NULL AND email <> ''
                  AND email_verified_at IS NOT NULL AND notify_mail = 1
                ORDER BY id LIMIT " . max(1, $limit));
}

/** 注册成功后的欢迎信（尽力而为，失败不影响注册） */
function mail_welcome(int $uid): void
{
    $c = mail_cfg();
    if (!$c['welcome']) {
        return;
    }
    $u = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ?', [$uid]);
    if (!$u || (string)$u['email'] === '' || $u['email_verified_at'] === null) {
        return;
    }
    $rows = [
        ['账号', (string)$u['username']],
        ['站点', base_url()],
    ];
    $html = mail_html(
        '欢迎使用 ' . app_name(),
        '账号已经开通，直接登录即可开始使用。',
        [
            '登录后你会有一个只属于自己的空间，往里存的内容别人看不到。',
            '需要给 Agent 调用的话，到「我的 API Key」里生成一个 Key，绑定到对应分组。',
            '如果要换密码或改昵称，在「账户设置」里操作。',
        ],
        null,
        $rows
    );
    mail_send((string)$u['email'], '欢迎使用 ' . app_name(), $html, '', 'welcome', $uid);
}
